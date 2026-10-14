# Installation

## Requirements

- Windows 10/11 with [XAMPP](https://www.apachefriends.org/) (Apache + PHP 8.0+)
- One of these PHP extensions (all shipped with XAMPP): curl, fileinfo, json,
  mbstring, openssl, zlib
- A browser on any device in your home network (PC, phone, Smart TV)

## Install

1. Stop Apache if it is running.
2. Copy the `MovieWebsite` folder into `C:\xampp\htdocs\MovieWebsite`.
3. Start **Apache** in the XAMPP Control Panel.
4. Open <http://localhost/MovieWebsite/> — you should see the homepage with an
   empty-library message.
5. Verify the API: <http://localhost/MovieWebsite/api/health.php> should return
   `"ok": true`.

Apache needs `AllowOverride All` for this project (default in XAMPP; verify in
`httpd.conf` if pages return 404 while PHP works elsewhere).

## Choose your movie folder (important on small SSDs)

1. Click **Library Settings** (top right).
2. First run: create the administrator password (min. 8 characters). It protects
   folder changes only — watching needs no password.
3. Click **Change Folder** → pick a drive (C:, D:, external USB…) → navigate to
   your movie folder → **Use this folder**.
4. The app scans immediately and shows how many movies it found.

Your choice is saved server-side in `data\library.json` and survives reloads
and Apache/PC restarts. Movies never need to be copied into the project.

## Access from your phone / TV (home Wi-Fi)

1. Find your PC's LAN IP: `ipconfig` in PowerShell/CMD → "IPv4 Address", e.g.
   `192.168.100.2`.
2. On the other device open `http://192.168.100.2/MovieWebsite/`.
3. Windows Firewall: allow Apache (`httpd.exe`) on **Private** networks when
   prompted. If the site does not open, check:
   - Apache is running,
   - firewall rule for TCP port 80 exists for httpd.exe,
   - both devices are on the same Wi-Fi/network.

Do **not** enable port forwarding on your router or expose this to the internet
— there is no TLS and no per-user auth for watching.

## Optional: TMDB metadata

Create `C:\xampp\htdocs\MovieWebsite\includes\config.local.php`:

```php
<?php
return [
    'tmdb' => [
        'api_key' => 'YOUR_TMDB_API_KEY_V3',
    ],
];
```

Restart is not required. Without a key everything still works (filename-derived
titles and fallback poster art); `api/metadata.php?action=status` reports
`"configured": false`.

## Optional: keep the project in version control

`.gitignore` already excludes your movies, runtime data, and secrets
(`assets/movies/`, `data/*.json`, `includes/config.local.php`).

## Uninstall

Delete the folder. Optionally also delete `data\` contents if you want progress
and settings gone. Your movie files are never touched by the app.
