#!/usr/bin/env bash
set -euo pipefail

DB_NAME="${WORDPRESS_DB_NAME:?WORDPRESS_DB_NAME not set}"
DB_USER="${WORDPRESS_DB_USER:?WORDPRESS_DB_USER not set}"
DB_PASS="${WORDPRESS_DB_PASSWORD:?WORDPRESS_DB_PASSWORD not set}"
DB_HOST="${DB_HOST:-mysql}"

BATCH_SIZE="${SEED_BATCH_SIZE:-1000000}"   # must be ≤ 1_000_000
BATCHES="${SEED_BATCHES:-10}"              # number of batches
TOTAL=$(( BATCH_SIZE * BATCHES ))
BLOCK_NAME="dmg-read-more/dmg-read-more"
DRY_RUN="${DRY_RUN:-false}"
CLEANUP="${CLEANUP:-false}"

# Error handling
trap 'echo "❌ Error occurred. Exiting..."; exit 1' ERR

# Function to format numbers with thousands separators
format_number() {
  local num=$1
  echo "$num" | sed ':a;s/\B[0-9]\{3\}\>/,&/;ta'
}

echo "⚡ Seeding $TOTAL posts (+ Gutenberg block + meta) into $DB_NAME"
echo "Batch size: $BATCH_SIZE | Batches: $BATCHES"
echo "Dry run: $DRY_RUN | Cleanup: $CLEANUP"

# Cleanup mode
if [ "$CLEANUP" = "true" ]; then
  echo "🧹 Cleaning up generated posts..."
  mariadb -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" <<EOSQL
    DELETE FROM wp_postmeta WHERE post_id IN (
      SELECT ID FROM wp_posts WHERE post_name LIKE 'generated-post-%'
    );
    DELETE FROM wp_posts WHERE post_name LIKE 'generated-post-%';
EOSQL
  echo "✅ Cleanup complete"
  exit 0
fi

# Dry run mode
if [ "$DRY_RUN" = "true" ]; then
  echo "🔍 DRY RUN: Would insert $TOTAL posts across $BATCHES batches"
  exit 0
fi

# --- 1️⃣ Create permanent sequence table if not exists ---
echo "Checking/creating permanent sequence table seq_1_to_1000000..."
mariadb -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" <<EOSQL
CREATE TABLE IF NOT EXISTS seq_1_to_1000000 (
    seq BIGINT PRIMARY KEY
) ENGINE=InnoDB;
EOSQL

# Populate sequence table if empty
EXISTING_COUNT=$(mariadb -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" -sN -e "USE $DB_NAME; SELECT COUNT(*) FROM seq_1_to_1000000;")
if [ "$EXISTING_COUNT" -lt 1000000 ]; then
  echo "Populating seq_1_to_1000000..."
  CHUNK=100000  # Increased from 10k for better performance
  for ((START=1; START<=1000000; START+=CHUNK)); do
    END=$((START+CHUNK-1))
    VALUES=$(seq $START $END | sed 's/^/(/;s/$/)/' | paste -sd, -)
    mariadb -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "USE $DB_NAME; INSERT IGNORE INTO seq_1_to_1000000 (seq) VALUES $VALUES;"
    echo "Inserted $END sequences..."
  done
fi
echo "✅ seq_1_to_1000000 ready"

# --- 2️⃣ Seed posts in batches ---
total_blocks_inserted=0
total_time=0

for ((i=0;i<BATCHES;i++)); do
  START_SEQ=$(( i * BATCH_SIZE + 1 ))
  echo "Batch $((i+1))/$BATCHES — rows ${START_SEQ}..$((START_SEQ + BATCH_SIZE - 1))"

  BATCH_START=$(date +%s)

  # Capture only numeric count
  BLOCKS_THIS_BATCH=$(mariadb -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" -sN "$DB_NAME" <<EOSQL
USE $DB_NAME;
SET autocommit = 0;
SET unique_checks = 0;
SET foreign_key_checks = 0;
SET sql_log_bin = 0;

-- Get admin user ID
SET @admin_user_id := (SELECT ID FROM wp_users WHERE user_login = 'admin' LIMIT 1);
SET @admin_user_id := IFNULL(@admin_user_id, 1);

-- Capture starting ID before insert (safer than LAST_INSERT_ID)
SET @start_id := (SELECT IFNULL(MAX(ID), 0) + 1 FROM wp_posts);
SET @end_id := @start_id + ${BATCH_SIZE} - 1;

-- Insert posts with random block (10-15% will have blocks) + realistic HTML content
INSERT INTO wp_posts (
  post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt,
  post_status, comment_status, ping_status, post_password, post_name, to_ping, pinged,
  post_modified, post_modified_gmt, post_content_filtered, post_parent, guid,
  menu_order, post_type, post_mime_type, comment_count
)
SELECT
  @admin_user_id,
  NOW() - INTERVAL FLOOR(RAND() * 365) DAY,  -- Each row gets different random date
  CONVERT_TZ(NOW() - INTERVAL FLOOR(RAND() * 365) DAY, @@session.time_zone, '+00:00'),
  CONCAT(
    -- Opening paragraph with varied content
    '<p>',
    ELT(FLOOR(1 + RAND()*10),
      'In the rapidly evolving landscape of digital technology, businesses must adapt to stay competitive.',
      'The transformation of modern industry relies heavily on innovative solutions and creative problem-solving.',
      'As we navigate through an era of unprecedented change, understanding core principles becomes essential.',
      'Effective communication strategies are fundamental to building strong organizational relationships.',
      'The intersection of technology and human experience creates opportunities for meaningful innovation.',
      'Market dynamics continue to shift as consumer preferences evolve in unexpected directions.',
      'Strategic planning requires careful consideration of both short-term goals and long-term vision.',
      'Digital transformation has fundamentally altered how we approach traditional business challenges.',
      'Understanding customer needs through data analysis provides valuable insights for growth.',
      'Collaborative efforts across departments lead to more comprehensive and effective solutions.'
    ),
    '</p>\n\n',
    -- Middle section with headings and lists
    CASE WHEN RAND() > 0.5 THEN CONCAT(
      '<h2>',
      ELT(FLOOR(1 + RAND()*8),
        'Key Considerations',
        'Important Factors',
        'Strategic Insights',
        'Essential Elements',
        'Core Principles',
        'Critical Success Factors',
        'Best Practices',
        'Implementation Guidelines'
      ),
      '</h2>\n<ul>\n',
      '<li>',
      ELT(FLOOR(1 + RAND()*12),
        'Comprehensive analysis of market trends and competitive positioning',
        'Integration of cutting-edge technologies with existing infrastructure',
        'Development of scalable solutions that accommodate future growth',
        'Emphasis on user experience and customer satisfaction metrics',
        'Continuous improvement through iterative feedback and optimization',
        'Risk management strategies to mitigate potential challenges',
        'Resource allocation aligned with organizational priorities',
        'Performance measurement using data-driven methodologies',
        'Cross-functional collaboration to leverage diverse expertise',
        'Innovation fostered through experimentation and learning',
        'Sustainability considerations in long-term planning',
        'Adaptability to changing market conditions and requirements'
      ),
      '</li>\n<li>',
      ELT(FLOOR(1 + RAND()*12),
        'Robust security measures protecting sensitive information and assets',
        'Transparency in operations building trust with stakeholders',
        'Quality assurance processes ensuring consistent deliverables',
        'Training and development programs enhancing team capabilities',
        'Customer engagement strategies driving loyalty and retention',
        'Operational efficiency through process automation and optimization',
        'Financial sustainability supporting long-term viability',
        'Ethical practices aligned with corporate values and standards',
        'Technology infrastructure supporting business objectives',
        'Market research informing strategic decisions',
        'Brand positioning creating competitive differentiation',
        'Partnership development expanding capabilities and reach'
      ),
      '</li>\n</ul>\n\n'
    ) ELSE '' END,
    -- Block insertion point (15-30%)
    CASE WHEN RAND() <= 0.225  -- 22.5% average (between 15-30%)
      THEN CONCAT('<!-- wp:', '${BLOCK_NAME}', " /-->\n\n")
      ELSE ''
    END,
    -- Closing paragraph with varied content
    '<p>',
    ELT(FLOOR(1 + RAND()*10),
      'Looking forward, organizations must remain agile and responsive to emerging opportunities and challenges in the marketplace.',
      'Success in today\'s environment demands a commitment to excellence, innovation, and continuous learning across all levels.',
      'By focusing on sustainable practices and stakeholder value, companies can build lasting competitive advantages.',
      'The integration of diverse perspectives and expertise enables more robust solutions to complex problems.',
      'Investing in people, processes, and technology creates a foundation for sustained growth and profitability.',
      'Embracing change while maintaining core values helps organizations navigate uncertainty with confidence.',
      'Through strategic partnerships and collaboration, businesses can achieve outcomes greater than the sum of their parts.',
      'Data-informed decision-making combined with creative thinking drives breakthrough innovations and results.',
      'Building resilient systems and cultures prepares organizations to thrive despite market volatility.',
      'Ultimately, the pursuit of excellence requires dedication, vision, and unwavering commitment to continuous improvement.'
    ),
    '</p>'
  ),
  CONCAT('Generated Post #', ${START_SEQ} + s.seq -1),
  '',
  'publish',
  'open',
  'open',
  '',
  LOWER(CONCAT('generated-post-', ${START_SEQ} + s.seq -1)),
  '',
  '',
  NOW(),
  CONVERT_TZ(NOW(), @@session.time_zone, '+00:00'),
  '',
  0,
  CONCAT('http://example.com/?p=', ${START_SEQ} + s.seq -1),
  0,
  'post',
  '',
  0
FROM seq_1_to_1000000 AS s
WHERE s.seq <= ${BATCH_SIZE}  -- Fixed: Use WHERE instead of table name
LIMIT ${BATCH_SIZE};

-- Update actual end ID after insert to account for any gaps
SET @actual_end_id := (SELECT MAX(ID) FROM wp_posts WHERE ID >= @start_id);

-- Insert _has_dmg_read_more_block meta only for posts with the block
INSERT INTO wp_postmeta (post_id, meta_key, meta_value)
SELECT p.ID, '_has_dmg_read_more_block', '1'
FROM wp_posts p
WHERE p.ID >= @start_id AND p.ID <= @actual_end_id
  AND p.post_content LIKE CONCAT('%<!-- wp:', '${BLOCK_NAME}', '%');

-- Return numeric count of meta rows inserted
SELECT ROW_COUNT();

COMMIT;
SET autocommit = 1;
SET unique_checks = 1;
SET foreign_key_checks = 1;
SET sql_log_bin = 1;
EOSQL
)

  BATCH_END=$(date +%s)
  BATCH_TIME=$((BATCH_END - BATCH_START))
  total_time=$((total_time + BATCH_TIME))

  total_blocks_inserted=$(( total_blocks_inserted + BLOCKS_THIS_BATCH ))
done

echo ""
echo "✅ Done"
echo "$(format_number $TOTAL) posts inserted into $DB_NAME"
echo "Total blocks (meta) inserted: $(format_number $total_blocks_inserted)"
echo "Block percentage: $(awk "BEGIN {printf \"%.2f%%\", ($total_blocks_inserted / $TOTAL) * 100}")"
echo "Total time: ${total_time}s (avg: $((total_time / BATCHES))s per batch)"