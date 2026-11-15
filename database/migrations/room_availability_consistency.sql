-- Merge duplicate room names within a dorm while preserving every booking.
CREATE TEMPORARY TABLE room_merge_map (
  duplicate_id INT PRIMARY KEY,
  keep_id INT NOT NULL
);

INSERT INTO room_merge_map (duplicate_id, keep_id)
SELECT ranked.id,
       ranked.keep_id
FROM (
  SELECT r.id,
         COALESCE(
           MIN(CASE WHEN EXISTS (SELECT 1 FROM bookings b WHERE b.room_id = r2.id) THEN r2.id END),
           MIN(r2.id)
         ) AS keep_id
  FROM rooms r
  JOIN rooms r2
    ON r2.dorm_id = r.dorm_id
   AND LOWER(TRIM(r2.name)) = LOWER(TRIM(r.name))
  GROUP BY r.id
) ranked
WHERE ranked.id <> ranked.keep_id;

UPDATE bookings b
JOIN room_merge_map m ON m.duplicate_id = b.room_id
SET b.room_id = m.keep_id;

DELETE r
FROM rooms r
JOIN room_merge_map m ON m.duplicate_id = r.id;

DROP TEMPORARY TABLE room_merge_map;

-- Approved bookings determine whether a room is full.
UPDATE rooms r
LEFT JOIN (
  SELECT room_id, COUNT(*) AS approved_count
  FROM bookings
  WHERE status = 'approved'
  GROUP BY room_id
) occupancy ON occupancy.room_id = r.id
SET r.status = CASE
  WHEN COALESCE(occupancy.approved_count, 0) >= r.occupancy THEN 'occupied'
  ELSE 'available'
END;

-- Add the constraint only when it is not already present.
SET @room_name_index_exists = (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'rooms'
    AND index_name = 'uq_rooms_dorm_name'
);
SET @room_name_index_sql = IF(
  @room_name_index_exists = 0,
  'ALTER TABLE rooms ADD UNIQUE INDEX uq_rooms_dorm_name (dorm_id, name)',
  'SELECT 1'
);
PREPARE room_name_index_statement FROM @room_name_index_sql;
EXECUTE room_name_index_statement;
DEALLOCATE PREPARE room_name_index_statement;
