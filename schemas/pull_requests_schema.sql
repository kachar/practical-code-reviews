CREATE TABLE `pull_requests` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `external_id` INT(10) UNSIGNED NULL DEFAULT NULL,
    `owner` VARCHAR(128) NULL DEFAULT NULL COLLATE 'utf8_unicode_ci',
    `repo` VARCHAR(128) NULL DEFAULT NULL COLLATE 'utf8_unicode_ci',
    `created_at` DATETIME NULL DEFAULT NULL,
    `merged_at` DATETIME NULL DEFAULT NULL,
    `closed_at` DATETIME NULL DEFAULT NULL,
    `time_to_first_comment` INT(11) NULL DEFAULT NULL,
    `time_before_merge` INT(11) NULL DEFAULT NULL,
    `time_before_close` INT(11) NULL DEFAULT NULL,
    `comment_count` INT(10) UNSIGNED NULL DEFAULT NULL,
    `commit_count` INT(10) UNSIGNED NULL DEFAULT NULL,
    `reviews_count` INT(10) UNSIGNED NULL DEFAULT NULL,
    `participants_count` INT(10) UNSIGNED NULL DEFAULT NULL,
    `additions` INT(10) UNSIGNED NULL DEFAULT NULL,
    `deletions` INT(10) UNSIGNED NULL DEFAULT NULL,
    `changes_total` INT(10) UNSIGNED NULL DEFAULT NULL,
    `state` VARCHAR(50) NULL DEFAULT NULL COLLATE 'utf8_unicode_ci',
    PRIMARY KEY (`id`),
    UNIQUE INDEX `external_id` (`external_id`),
    INDEX `organisation_repo` (`owner`, `repo`)
)
COLLATE='utf8_unicode_ci'
ENGINE=InnoDB
AUTO_INCREMENT=1
;
