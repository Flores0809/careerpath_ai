-- CareerPath AI — Migration 2: AI enrichment fields
-- Safe to run on an existing database (only ADDs columns, doesn't drop/reset
-- anything). Adds storage for Gemini 2.5 Flash-Lite-generated enrichment on
-- top of the raw crawled fields, so both versions are kept side by side.
--
-- Run this via phpMyAdmin: select the `careerpath_ai` database, go to the
-- SQL tab, paste this whole file, and click Go.

USE careerpath_ai;

ALTER TABLE pending_careers
    ADD COLUMN ai_description         TEXT NULL         AFTER qualifications,
    ADD COLUMN ai_daily_task           TEXT NULL         AFTER ai_description,
    ADD COLUMN ai_educational_pathway  VARCHAR(255) NULL AFTER ai_daily_task,
    ADD COLUMN ai_r_score              INT NULL          AFTER ai_educational_pathway,
    ADD COLUMN ai_i_score              INT NULL          AFTER ai_r_score,
    ADD COLUMN ai_a_score              INT NULL          AFTER ai_i_score,
    ADD COLUMN ai_s_score              INT NULL          AFTER ai_a_score,
    ADD COLUMN ai_e_score              INT NULL          AFTER ai_s_score,
    ADD COLUMN ai_c_score              INT NULL          AFTER ai_e_score,
    ADD COLUMN ai_enriched_at          TIMESTAMP NULL    AFTER ai_c_score;
