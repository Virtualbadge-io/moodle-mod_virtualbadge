# Changes

## 1.1.3 (2026-11-02)

- "Retry issuing" of a failed certificate first looks for a certificate already created in Virtualbadge, so a lost response no longer leads to a duplicate
- A reached hourly limit no longer counts as a failed attempt; the certificate is sent as soon as the limit allows it, also after a 429 from Virtualbadge with or without Retry-After
- Certificates issued after a course reset are new ones, the plugin no longer takes over a certificate from before the reset
- Restored certificates stay in the organisation they were issued in
- The call counter of the hourly limit is safe with several cron runners at the same time
- Issuing to more than 50 participants at once runs in the background
- New setting for the date format of template fields, default DD.MM.YYYY as before
- English field names such as "First name", "End date" or "Completion date" are mapped automatically
- README: Virtualbadge.io issues Open Badges, W3C Verifiable Credentials, and SD-JWT VC and mdoc for the EUDI Wallet

## 1.1.2 (2026-10-30)

First public release.

- Virtualbadge activity: choose a Virtualbadge template and map each template field to fixed text, user fields, custom profile fields, course settings, custom course fields, completion date, course grade, points or a single grade item
- Issue on course completion, on course completion after release by a trainer, on a minimum grade or by manual release
- Learners see the link to their certificate and the validation page in the activity
- Status list for trainers: release single, selected or all participants, retry issuing, send the email again, issue again (the old certificate becomes invalid), issue to everyone who already met the condition
- Certificates are sent in the background with automatic retries, a reference per certificate prevents duplicates, configurable hourly call limit
- Hourly check against Virtualbadge: revoked, replaced and deleted certificates are shown as such in Moodle
- Check "Virtualbadge certificates" in Site administration > Reports > System status: failed and retried certificates, certificates by status and the affected activities with the latest error
- Several Virtualbadge organisations with their own API key, mapped to course categories
- API keys stored encrypted
- Activity completion "certificate issued", backup and restore, course reset, events, privacy API
