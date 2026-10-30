# Changes

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
