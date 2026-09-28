# Security Policy

## Reporting a Vulnerability

Please report security vulnerabilities **privately** using GitHub's
[Report a vulnerability](https://github.com/FacturaScripts/backup/security/advisories/new)
button. Do not open public issues for security matters.

We aim to acknowledge reports within 5 business days.

If the vulnerability affects FacturaScripts core rather than this plugin,
please report it to the
[core repository](https://github.com/FacturaScripts/facturascripts/security/advisories/new)
instead.

## Supported Versions

Only the latest stable release of the Backup plugin receives security fixes.

## Scope

This plugin follows the
[FacturaScripts security policy](https://github.com/FacturaScripts/facturascripts/blob/master/SECURITY.md).
Everything listed as out of scope there is also out of scope here.

### In scope

- Downloading, listing or deleting backup files without being authenticated,
  or without access to the **Backup** page.
- Actions allowed on the **Backup** page to a user whose role lacks the
  permission for that action (export, import, update or delete).
- Getting a valid download token for a backup file without access to the
  **Backup** page. Files in `MyFiles` can only be downloaded over HTTP with
  their per-file token. A way to download them without a valid token affects
  core and should be reported there.
- Path traversal through backup file names or through the contents of an
  uploaded backup, if it lets someone read or write files outside the
  expected folders.
- CSRF on backup creation, restoration or deletion.
- SQL injection, stored XSS affecting other users, and similar issues in the
  plugin's code.

### Out of scope

1. **Access granted by design.** The Backup page lets users with access to it
   download the full database and files, and restore them. Restoring a
   backup replaces all data and can create an administrator account. A user
   who has been given access to this page by an administrator is trusted to
   do this.
2. **Malicious backups uploaded by a trusted user.** Restoring a backup runs
   its SQL and extracts its files, which can include plugins with PHP code.
   Reports whose PoC is "restore a crafted backup" are out of scope unless
   they bypass the permission checks above or escape the intended folders.
3. **Backup files exposed by the web server configuration**, for example a
   server set up to serve the `MyFiles` folder directly.
4. **Memory, time or disk exhaustion** caused by creating or restoring large
   backups.

## Acknowledgements

Researchers who report valid, in-scope vulnerabilities will be credited
in the release notes of the fixed version, unless they prefer to remain
anonymous.
