# pinecreekradio
PineCreekRadio.com

## Music bingo imagery

- `media/music-bingo-card-screenshot.jpeg` is the owner-supplied card screenshot. CSS frames its complete middle card without changing the original file.
- `media/music-bingo-friends.webp` is an AI-generated promotional illustration, created with the built-in image-generation tool and converted to WebP for loading speed. The page identifies it as illustrative, not an actual event photo. Replace it with an event photo and update the caption and alt text when real photography is available.

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
