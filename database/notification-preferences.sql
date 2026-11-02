CREATE TABLE IF NOT EXISTS notification_preferences (
    user_id INT NOT NULL PRIMARY KEY,
    tenant_alerts TINYINT(1) NOT NULL DEFAULT 1,
    announcement_emails TINYINT(1) NOT NULL DEFAULT 1,
    booking_alerts TINYINT(1) NOT NULL DEFAULT 1,
    verification_alerts TINYINT(1) NOT NULL DEFAULT 1,
    summary_emails TINYINT(1) NOT NULL DEFAULT 1
);
