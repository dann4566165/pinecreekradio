# pinecreekradio
PineCreekRadio.com

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
