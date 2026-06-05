#!/bin/bash
# RJPES Portal - Server Setup & Installation Script

# Exit immediately if a command exits with a non-zero status
set -e

echo "=========================================================="
echo "        RJPES Portal - Server Installation Setup          "
echo "=========================================================="
echo ""

# 1. Check Prerequisites
echo "--> 1. Checking Prerequisites..."

if ! command -v php &> /dev/null; then
    echo "❌ Error: PHP is not installed. Please install PHP 7.4+ or 8.x first."
    exit 1
else
    echo "✓ PHP is installed: $(php -v | head -n 1)"
fi

if ! command -v git &> /dev/null; then
    echo "⚠️ Warning: git command is not found. Ensure git is installed if using auto-webhooks."
else
    echo "✓ git is installed: $(git --version)"
fi

# 2. Check PHP extensions
echo ""
echo "--> 2. Checking required PHP extensions..."
REQUIRED_EXTS=("pdo" "pdo_mysql" "gd" "mbstring" "openssl")
for ext in "${REQUIRED_EXTS[@]}"; do
    if php -m | grep -qi "^${ext}$"; then
        echo "✓ Extension '$ext' is enabled."
    else
        echo "⚠️ Warning: Extension '$ext' is missing or disabled in php.ini!"
    fi
done

# 3. Configure folder permissions
echo ""
echo "--> 3. Configuring folder permissions..."

# Create uploads directory if it does not exist
if [ ! -d "uploads" ]; then
    echo "Creating uploads directory..."
    mkdir -p uploads
fi

echo "Setting directory permissions..."
chmod -R 775 uploads
chmod -R 775 config

# Attempt to detect standard web server users to configure ownership
WEB_USERS=("www-data" "apache" "nginx" "_www" "nobody" "www")
FOUND_USER=""

for user in "${WEB_USERS[@]}"; do
    if id "$user" &>/dev/null; then
        FOUND_USER="$user"
        break
    fi
done

if [ -n "$FOUND_USER" ]; then
    echo "✓ Detected web server user: '$FOUND_USER'"
    echo "Changing folder ownership to web server user..."
    # Suppress errors if run without root privileges
    chown -R "$FOUND_USER":"$FOUND_USER" uploads 2>/dev/null || true
    chown -R "$FOUND_USER":"$FOUND_USER" config 2>/dev/null || true
    echo "✓ Ownership updated successfully."
else
    echo "⚠️ Warning: Could not detect standard web server user (www-data/nginx/etc.)."
    echo "Please ensure your web server user has write permissions for 'uploads/' and 'config/' directories."
fi

# 4. Final steps
echo ""
echo "=========================================================="
echo "🎉 System Setup Check Complete!"
echo "=========================================================="
echo "To complete the database and site configuration:"
echo "1. Open your browser and navigate to:"
echo "   http://<your-server-domain-or-ip>/install.php"
echo "2. Input your MySQL database host, credentials, and name."
echo "3. The web installer will automatically create the database,"
echo "   run config/schema.sql, and generate config/db.php."
echo "=========================================================="
echo ""
