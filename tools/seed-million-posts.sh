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

echo "⚡ Seeding $TOTAL posts (+ Gutenberg block + meta) into $DB_NAME"
echo "Batch size: $BATCH_SIZE | Batches: $BATCHES"

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
  CHUNK=10000
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

for ((i=0;i<BATCHES;i++)); do
  START_SEQ=$(( i * BATCH_SIZE + 1 ))
  echo "Batch $((i+1))/$BATCHES — rows ${START_SEQ}..$((START_SEQ + BATCH_SIZE - 1))"

  # Capture only numeric count
  BLOCKS_THIS_BATCH=$(mariadb -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" -sN "$DB_NAME" <<EOSQL
USE $DB_NAME;
SET autocommit = 0;
SET unique_checks = 0;
SET foreign_key_checks = 0;
SET sql_log_bin = 0;

SET @rand_days := FLOOR(RAND() * 365);

-- Insert posts with random block + two random words
INSERT INTO wp_posts (
  post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt,
  post_status, comment_status, ping_status, post_password, post_name, to_ping, pinged,
  post_modified, post_modified_gmt, post_content_filtered, post_parent, guid,
  menu_order, post_type, post_mime_type, comment_count
)
SELECT
  1,
  NOW() - INTERVAL @rand_days DAY,
  CONVERT_TZ(NOW() - INTERVAL @rand_days DAY, @@session.time_zone, '+00:00'),
  CONCAT(
    REPEAT('Lorem ipsum ', FLOOR(RAND()*10)+5),
    CASE WHEN FLOOR(RAND()*2)=1
      THEN CONCAT('\n<!-- wp:', '${BLOCK_NAME}', " /-->\n")
      ELSE ''
    END,
    ' ',
    ELT(FLOOR(1 + RAND()*4), 'hello','punctual','Lord','more'),
    ' ',
    ELT(FLOOR(1 + RAND()*4), 'hello','punctual','Lord','more')
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
FROM seq_1_to_${BATCH_SIZE} AS s;

-- Insert _has_dmg_read_more_block meta
INSERT INTO wp_postmeta (post_id, meta_key, meta_value)
SELECT p.ID, '_has_dmg_read_more_block',
  CASE WHEN p.post_content LIKE CONCAT('%', '${BLOCK_NAME}', '%') THEN 1 ELSE 0 END
FROM wp_posts p
WHERE p.ID BETWEEN LAST_INSERT_ID() AND LAST_INSERT_ID() + ${BATCH_SIZE} -1;

-- Return numeric count of blocks inserted this batch
SELECT COUNT(*) FROM wp_posts
WHERE post_content LIKE CONCAT('%', '${BLOCK_NAME}', '%')
  AND ID BETWEEN LAST_INSERT_ID() AND LAST_INSERT_ID() + ${BATCH_SIZE} -1;

COMMIT;
SET autocommit = 1;
SET unique_checks = 1;
SET foreign_key_checks = 1;
SET sql_log_bin = 1;
EOSQL
)

  echo "Blocks inserted this batch: $BLOCKS_THIS_BATCH"
  total_blocks_inserted=$(( total_blocks_inserted + BLOCKS_THIS_BATCH ))
done

echo "✅ Done: $TOTAL posts with Gutenberg block and meta inserted into $DB_NAME"
echo "Total blocks inserted: $total_blocks_inserted"