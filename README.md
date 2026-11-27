# pinecreekradio
PineCreekRadio.com

## Music bingo imagery

- `media/music-bingo-card-screenshot.jpeg` is the owner-supplied card screenshot. CSS frames its complete middle card without changing the original file.
- `media/bingocelebration.png` is the promotional image used in the music bingo section.

Image-generation prompt:

> Use case: photorealistic-natural. Asset type: wide landscape website image for Live Band Music Bingo. Create a believable candid promotional scene of six adult friends aged 30–60 having a wonderful time playing music bingo around two small tables in a cozy neighborhood music bar. One person laughs holding up a bingo card, another circles a song on their card with a pen, others sing along and smile. Cream paper cards with 5 by 5 song grids, dark navy headings, subtle teal and orange accents. Cards should look like music bingo, not numeric bingo. Warm natural amber string lighting, realistic skin texture, relaxed expressions, casual clothing, mixed men and women. In the softly out of focus background a small duo plays guitar and electronic drums; they are generic musicians, do not imply specific real band members or reproduce a real event. Focus on friends and visible paper cards in the foreground. Editorial photography style, natural candid energy, horizontal 3:2 composition, no overlaid text, no watermark. The website will caption this as an AI-generated illustration.

## Deployment

Production deploys run from `.github/workflows/deploy.yml` when changes are pushed to `main`.

Required GitHub repository secret:

- `SSH_PRIVATE_KEY`: private key for an SSH key that can log in to `root@45.79.181.88`

The workflow deploys this repository with `rsync --delete` to:

```text
/var/www/pinecreekradio.com/
```

Server checklist:

- Add the matching public key to `/root/.ssh/authorized_keys` on the Linode server.
- Create the web root with `sudo mkdir -p /var/www/pinecreekradio.com`.
- Configure the web server to serve `pinecreekradio.com` and `www.pinecreekradio.com` from `/var/www/pinecreekradio.com`.
- Point DNS `A` records for `pinecreekradio.com` and `www.pinecreekradio.com` to `45.79.181.88`.

## Booking contact form

The homepage posts to `contact.php` on the same domain. The handler sends a
plain-text booking inquiry to the fixed booking inbox, uses the customer's
email as Reply-To, and returns success only when the email provider accepts
the request. No email credentials are included in the site or browser.

The PHP web server must expose the existing Songlist mail environment to
`/var/www/pinecreekradio.com/contact.php` as well as `/songs/`:

- `RESEND_API_KEY` (existing server secret).
- `AUTH_EMAIL_FROM` (verified sender; defaults to `songlist@pinecreekradio.com`).
- Optional `BOOKING_EMAIL_FROM` to use another verified sender.
- `AUTH_EMAIL_PROVIDER` defaults to `resend`; an optional
  `BOOKING_EMAIL_PROVIDER` overrides it. Postmark also works with
  `POSTMARK_SERVER_TOKEN`. Log-only delivery is intentionally unsupported.

Set these in the existing Apache/PHP runtime configuration, not in Git or
client-side HTML. No new mail account, database, or dependency installation is
required on the existing PHP host. PHP needs cURL or HTTPS URL streams and a
writable system temporary directory for the locked spam rate-limit file.
The endpoint checks requests using the actual network address; if the server
is behind Cloudflare, ensure Apache's trusted-proxy remote-IP configuration
is correct.

Deployment runs the PHP tests, uploads the PHP handler, and checks its readiness
before replacing the existing homepage. If mail configuration is scoped only
to the Songlist directory, expose the same settings to the booking endpoint
and rerun the deployment. Readiness does not itself send an email; after deploy
submit an inquiry and confirm receipt in the booking inbox.

Local validation (no email sent):

```sh
php -l contact.php
php tests/contact.test.php
```
