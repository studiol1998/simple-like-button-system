-- Pollyanna v0.4.1 existing installations: expand identifiers to 100 Unicode code points.
-- Back up the database before applying this migration.

ALTER TABLE pollyanna_likes
    MODIFY identifier VARCHAR(100) NOT NULL;

ALTER TABLE pollyanna_rate_limit_events
    MODIFY identifier VARCHAR(100) NOT NULL;

ALTER TABLE pollyanna_lifetime_totals
    MODIFY identifier VARCHAR(100) NOT NULL;

ALTER TABLE pollyanna_period_aggregates
    MODIFY identifier VARCHAR(100) NOT NULL;

ALTER TABLE pollyanna_identifier_urls
    MODIFY identifier VARCHAR(100) NOT NULL;
