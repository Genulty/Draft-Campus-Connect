# Deploying Campus Connect on Amazon Lightsail

## 1. Create the instance
1. Lightsail console → **Create instance**.
2. Platform **Linux/Unix** → Blueprint **OS Only → Ubuntu 24.04 LTS** (22.04 also works).
3. Plan: the smallest plan is enough.
4. **Create instance**, wait until it says *Running*.

## 2. Install and start the app
Click **Connect using SSH** (the terminal icon on the instance), then run:

```bash
git clone https://github.com/genulty/draft-campus-connect.git
cd draft-campus-connect
sudo bash deploy/lightsail-setup.sh
```

The script installs nginx, PHP (PHP-FPM) and MySQL, creates the `campus_connect` database
and loads all the project data, and serves the site on port 80: nginx sends the website files
from `public/` and passes every `/api/...` request to `api/index.php` in PHP-FPM. Everything
starts again automatically after a reboot. When it finishes it prints the address, e.g. `http://3.91.12.34`.

If the server still runs the older Node.js version, the same script switches it to PHP: it stops and
removes the old service and keeps the same MySQL password and database.

> **Private repo?** `git clone` will ask for a username and password. Use your
> GitHub username and a [personal access token](https://github.com/settings/tokens)
> (fine-grained, *Contents: read-only* on this repo) as the password.

## 3. Open it
Go to `http://<instance public IP>` and log in with `student@campus.edu` /
`Campus123!`. Port 80 (HTTP) is open in the Lightsail firewall by default; if the
page doesn't load, check **Networking → IPv4 Firewall** on the instance.

Tip: under **Networking**, attach a **static IP** so the address doesn't change
when the instance is stopped and started.

## HTTPS (needed for most phones)
Phone browsers often refuse plain `http://` sites. To turn on HTTPS:
1. Lightsail console → your instance → **Networking** → IPv4 Firewall → **Add rule** → **HTTPS** (TCP 443) → Create.
2. In the SSH window: `sudo bash deploy/enable-https.sh`
3. Open the address it prints, e.g. `https://3-239-161-185.sslip.io`.

The certificate renews automatically. If you attach a static IP later, run the script again (the sslip.io name is built from the IP).

## phpMyAdmin (browse the database in a web page)
1. In the SSH window: `sudo bash deploy/enable-phpmyadmin.sh`
2. It prints a username (`dbadmin`) and a random password. Show them again any time with
   `sudo cat /etc/campus-connect/phpmyadmin-login.txt`.
3. Open `<your site address>/phpmyadmin/` (for example `http://3.239.161.185/phpmyadmin/`) and log in.

The `dbadmin` login can only see and change the `campus_connect` database. Turn on HTTPS first if you can,
so the password isn't sent in plain text.

## Updating after new commits
```bash
cd ~/draft-campus-connect
git pull
sudo bash deploy/lightsail-setup.sh
```

## Useful commands
| What | Command |
|---|---|
| Error log | `sudo tail -n 50 /var/log/nginx/error.log` |
| Restart PHP | `sudo systemctl restart php8.3-fpm` |
| Restart nginx | `sudo systemctl restart nginx` |
| Reset the database | `sudo bash deploy/reset-db.sh` |
| Open MySQL | `sudo mysql campus_connect` |
| Run the sample queries | `sudo mysql campus_connect < db/sample-queries.sql` |
| Check the data against the SRS rules | `sudo mysql -t campus_connect < db/validate.sql` |

The MySQL user and password the website uses live in `/etc/campus-connect/config.php` (readable only by root and PHP).
