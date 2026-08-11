#
# Only the columns whose auto-generated definition differs between TYPO3 v13 and
# v14 are pinned here. Explicit definitions always win over auto-creation --
# see TYPO3 changelog #101553 "Auto-create DB fields from TCA columns".
#
# Everything else (uid, pid, tstamp, crdate, deleted, hidden, starttime,
# endtime, sorting, sys_language_uid, l10n_parent, l10n_source, l10n_state,
# l10n_diffsource, t3ver_*, the inline parent pointers and every business
# column) is generated from TCA by DefaultTcaSchema and must NOT be repeated
# here: repeating pid or sys_language_uid also suppresses the auto-created
# `parent` and `language_identifier` indexes.
#

#
# Table structure for table 'tx_modernextbasefrontendedit_domain_model_address'
#
CREATE TABLE tx_modernextbasefrontendedit_domain_model_address (
	# TCA type=select auto-creation uses DEFAULT '' on v13 but the TCA 'default'
	# on v14. Pinned here so both versions agree.
	type varchar(150) DEFAULT 'others' NOT NULL
);

#
# Table structure for table 'tx_modernextbasefrontendedit_domain_model_email'
#
CREATE TABLE tx_modernextbasefrontendedit_domain_model_email (
	type varchar(150) DEFAULT 'others' NOT NULL
);
