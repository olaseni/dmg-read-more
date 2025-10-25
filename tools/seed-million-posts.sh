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

-- Capture starting ID before insert (safer than LAST_INSERT_ID)
SET @start_id := (SELECT IFNULL(MAX(ID), 0) + 1 FROM wp_posts);

-- Get admin user ID
SET @admin_user_id := (SELECT ID FROM wp_users WHERE user_login = 'admin' LIMIT 1);
SET @admin_user_id := IFNULL(@admin_user_id, 1);

-- Insert posts with random block (10-15% will have blocks) + expanded word list
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
    REPEAT('Lorem ipsum ', FLOOR(RAND()*10)+5),
    CASE WHEN RAND() <= 0.125  -- 12.5% average (between 10-15%)
      THEN CONCAT('\n<!-- wp:', '${BLOCK_NAME}', " /-->\n")
      ELSE ''
    END,
    ' ',
    ELT(FLOOR(1 + RAND()*20), 'hello','world','test','search','query','performance',
        'database','index','optimize','scale','million','posts','wordpress','gutenberg',
        'block','meta','plugin','data','content','article'),
    ' ',
    ELT(FLOOR(1 + RAND()*20), 'hello','world','test','search','query','performance',
        'database','index','optimize','scale','million','posts','wordpress','gutenberg',
        'block','meta','plugin','data','content','article')
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

-- Insert _has_dmg_read_more_block meta only for posts with the block
INSERT INTO wp_postmeta (post_id, meta_key, meta_value)
SELECT p.ID, '_has_dmg_read_more_block', '1'
FROM wp_posts p
WHERE p.ID >= @start_id AND p.ID < @start_id + ${BATCH_SIZE}
  AND p.post_content LIKE CONCAT('%', '${BLOCK_NAME}', '%');

-- Return numeric count of blocks inserted this batch
SELECT COUNT(*) FROM wp_posts
WHERE post_content LIKE CONCAT('%', '${BLOCK_NAME}', '%')
  AND ID >= @start_id AND ID < @start_id + ${BATCH_SIZE};

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