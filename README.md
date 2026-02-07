# Mail Check

A simple, self-hosted Gmail unread counter using OAuth2.

## Features

- Check Gmail unread count without opening Gmail
- OAuth2 authentication (no password storage)
- Dark/light theme toggle
- Single-user, self-hosted

## Security Notice

**⚠️ DO NOT EXPOSE THIS APPLICATION DIRECTLY TO THE INTERNET**

This app is designed for single-user, authenticated access behind a reverse proxy. Without proper authentication, anyone with the URL could:
- Trigger OAuth flows using your credentials
- Access your Gmail data if already authenticated

## Setup

### 1. Get Google OAuth Credentials

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create a new project (or select existing)
3. Enable the **Gmail API**:
   - Navigate to "APIs & Services" > "Library"
   - Search for "Gmail API"
   - Click "Enable"
4. Configure OAuth consent screen:
   - "APIs & Services" > "OAuth consent screen"
   - Choose "Internal" (if using Google Workspace) or "External"
   - Fill in app name, user support email, developer contact
   - Add scope: `https://www.googleapis.com/auth/gmail.readonly`
5. Create credentials:
   - "APIs & Services" > "Credentials"
   - Click "Create Credentials" > "OAuth client ID"
   - Application type: "Web application"
   - Add authorized redirect URI: `https://mail.yourdomain.com/api.php?action=callback`
   - Click "Create"
   - Copy the **Client ID** and **Client Secret**

### 2. Configure the Application

1. Copy `config.json.example` to `data/config.json`:
   ```bash
   mkdir -p data
   cp config.json.example data/config.json
   ```

2. Edit `data/config.json` with your credentials:
   ```json
   {
     "client_id": "your-client-id.apps.googleusercontent.com",
     "client_secret": "GOCSPX-your-client-secret",
     "redirect_uri": "https://mail.yourdomain.com/api.php?action=callback"
   }
   ```

3. Set permissions:
   ```bash
   chmod 600 data/config.json
   ```

### 3. Deploy Behind Reverse Proxy (Required)

#### Option A: SWAG (Recommended for existing SWAG users)

1. Create SWAG subdomain config (`/config/nginx/proxy-confs/mail.subdomain.conf`):
   ```nginx
   server {
       listen 443 ssl http2;
       listen [::]:443 ssl http2;
       server_name mail.*;

       include /config/nginx/ssl.conf;

       location / {
           include /config/nginx/proxy.conf;
           include /config/nginx/resolver.conf;
           
           # Add authentication
           include /config/nginx/authelia-server.conf;
           
           proxy_pass http://mail-check:80;
       }
   }
   ```

2. Add to docker-compose.yml:
   ```yaml
   mail-check:
     image: aevrin/easy:latest
     container_name: mail-check
     volumes:
       - ./app:/app
       - ./data:/app/data
     networks:
       - swag
     restart: unless-stopped
   ```

3. Restart SWAG:
   ```bash
   docker restart swag
   ```

#### Option B: Standalone Nginx Reverse Proxy

1. Install nginx and certbot:
   ```bash
   sudo apt install nginx certbot python3-certbot-nginx
   ```

2. Create nginx config (`/etc/nginx/sites-available/mail`):
   ```nginx
   server {
       listen 80;
       server_name mail.yourdomain.com;
       return 301 https://$server_name$request_uri;
   }

   server {
       listen 443 ssl http2;
       server_name mail.yourdomain.com;

       ssl_certificate /etc/letsencrypt/live/mail.yourdomain.com/fullchain.pem;
       ssl_certificate_key /etc/letsencrypt/live/mail.yourdomain.com/privkey.pem;

       # Basic auth (replace with your auth method)
       auth_basic "Restricted Access";
       auth_basic_user_file /etc/nginx/.htpasswd;

       location / {
           proxy_pass http://localhost:8080;
           proxy_set_header Host $host;
           proxy_set_header X-Real-IP $remote_addr;
           proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
           proxy_set_header X-Forwarded-Proto $scheme;
       }
   }
   ```

3. Enable site and get SSL cert:
   ```bash
   sudo ln -s /etc/nginx/sites-available/mail /etc/nginx/sites-enabled/
   sudo certbot --nginx -d mail.yourdomain.com
   sudo nginx -t && sudo systemctl reload nginx
   ```

4. Create basic auth (if using):
   ```bash
   sudo apt install apache2-utils
   sudo htpasswd -c /etc/nginx/.htpasswd yourusername
   ```

#### Option C: Cloudflare Tunnel (Zero-open-ports)

1. Install cloudflared:
   ```bash
   wget https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-amd64.deb
   sudo dpkg -i cloudflared-linux-amd64.deb
   ```

2. Authenticate:
   ```bash
   cloudflared tunnel login
   ```

3. Create tunnel:
   ```bash
   cloudflared tunnel create mail-check
   cloudflared tunnel route dns mail-check mail.yourdomain.com
   ```

4. Create config (`~/.cloudflared/config.yml`):
   ```yaml
   tunnel: <tunnel-id>
   credentials-file: /home/user/.cloudflared/<tunnel-id>.json

   ingress:
     - hostname: mail.yourdomain.com
       service: http://localhost:8080
       originRequest:
         noTLSVerify: true
     - service: http_status:404
   ```

5. Run tunnel:
   ```bash
   cloudflared tunnel run mail-check
   ```

6. Add Cloudflare Access for authentication (in Cloudflare dashboard)

### 4. First Run

1. Navigate to `https://mail.yourdomain.com`
2. Click "Connect with Google"
3. Authorize the application
4. You'll be redirected back with your unread count

## File Structure

```
.
├── index.html          # Main UI
├── app.js              # Frontend logic
├── style.css           # Styling
├── api.php             # Backend API
├── config.json.example # Sample config
├── data/               # Runtime data (gitignored)
│   ├── config.json     # Your OAuth credentials
│   └── token.json      # OAuth tokens (auto-generated)
└── README.md
```

## Security Best Practices

1. **Never commit `data/` to git** - Add to `.gitignore`
2. **Use authentication** - SWAG/Authelia, HTTP basic auth, or Cloudflare Access
3. **Use HTTPS** - Required for OAuth2
4. **Restrict to internal network** - Or use VPN/VPC
5. **Set file permissions**:
   ```bash
   chmod 600 data/config.json
   chmod 600 data/token.json
   ```

## Troubleshooting

### "Missing client_id or redirect_uri"
- Check that `data/config.json` exists and is valid JSON
- Ensure all three fields are filled in

### "redirect_uri_mismatch"
- The `redirect_uri` in Google Console must exactly match the one in `config.json`
- Include the full path: `https://mail.yourdomain.com/api.php?action=callback`

### "Access denied" or 401 errors
- Click "Disconnect" and re-authenticate
- Check that Gmail API is enabled in Google Cloud Console
- Verify OAuth consent screen is published (if using "External")

### Token refresh fails
- Delete `data/token.json` and re-authenticate
- Check that `client_secret` hasn't changed in Google Console

## Privacy

- This app only requests `gmail.readonly` scope (cannot send/delete emails)
- Tokens are stored locally in `data/token.json`
- No data is sent to third parties
- No analytics or tracking

## License

MIT - Do whatever you want with it.
