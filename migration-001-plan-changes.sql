-- Run ONCE on an existing database (fresh installs already get these via schema.sql).
USE membership_app;

ALTER TABLE users
    ADD COLUMN pending_plan        ENUM('silver','gold') DEFAULT NULL AFTER membership_status,
    ADD COLUMN current_period_end  DATETIME DEFAULT NULL AFTER pending_plan;
