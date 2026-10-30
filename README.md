# Virtualbadge (mod_virtualbadge)

Moodle activity that issues certificates and badges in [Virtualbadge.io](https://www.virtualbadge.io) and shows the link to the certificate inside Moodle.

## Features

- Issue on course completion, on course completion after release by a trainer, when the course grade reaches a minimum, or on manual release
- Any Virtualbadge template: each template field is mapped to fixed text, a user field, a custom user profile field, a course field, a custom course field, the course grade or the course total in points
- Asynchronous sending with automatic retries, protection against duplicate certificates
- Participants see the link to their certificate and the validation page
- Trainers see a status list with course completion, release certificates, send the certificate email again, replace a certificate by a new one (the old one becomes invalid), retry failed certificates and issue certificates to everyone who already meets the condition
- Site administrators see failed and retried certificates in Site administration > Reports > System status
- Optional: several Virtualbadge organisations, each with its own API key, mapped to course categories

## Requirements

- Moodle 4.5 or later
- A Virtualbadge.io account with API access. Virtualbadge.io is an external, paid service for issuing and validating digital certificates and badges. All functions of this plugin depend on it: templates, issuing, links and validation pages come from Virtualbadge.io.
- The API key is shown in the Virtualbadge admin panel under Account Settings, Integration.

Without an API key the plugin installs normally, but the activity cannot be set up: the activity form shows a notice to contact the site administrator.

## Installation

Install the ZIP package in Site administration > Plugins > Install plugins, or copy this directory to `mod/virtualbadge` (Moodle 5.1 and later: `public/mod/virtualbadge`) and visit Site administration > Notifications.

After the installation enter the API key (see Configuration). No other steps are needed, the plugin uses the standard Moodle cron.

## Configuration

Site administration > Plugins > Activity modules > Virtualbadge:

- API key of your Virtualbadge organisation
- Calls per hour: the limit agreed with Virtualbadge (default 200)
- Optional: further organisations with their own API key, and a mapping of course categories to organisations

## Privacy

To issue a certificate the plugin sends name, email address, completion date and the values of the mapped template fields to Virtualbadge.io. Deleting data in Moodle does not revoke certificates in Virtualbadge.

## Support

Questions about the plugin or Virtualbadge.io: support@virtualbadge.io. Bugs and feature requests: https://github.com/Virtualbadge-io/moodle-mod_virtualbadge/issues

## License

GNU GPL v3 or later.
