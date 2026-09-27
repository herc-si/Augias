#!/bin/sh
set -e

# Create augias system user and group if they don't exist
# Supports both glibc-based distros (groupadd/useradd) and Alpine/BusyBox (addgroup/adduser)
if ! getent group augias >/dev/null 2>&1; then
    if command -v groupadd >/dev/null 2>&1; then
        groupadd --system augias
    else
        addgroup -S augias
    fi
fi

if ! getent passwd augias >/dev/null 2>&1; then
    if command -v useradd >/dev/null 2>&1; then
        useradd --system \
            --gid augias \
            --home-dir /var/lib/augias \
            --no-create-home \
            --shell /usr/sbin/nologin \
            --comment "Augias service account" \
            augias
    else
        adduser -S -G augias -h /var/lib/augias \
            -s /usr/sbin/nologin -D augias
    fi
fi

# Ensure directories exist with correct ownership
install -d -m 0750 -o augias -g augias /var/lib/augias
install -d -m 0750 -o root -g augias /etc/augias

# Fix ownership on config files
if [ -f /etc/augias/augias.env ]; then
    chown root:augias /etc/augias/augias.env
    chmod 0640 /etc/augias/augias.env
fi

# Reload systemd to pick up the new service file
if command -v systemctl >/dev/null 2>&1; then
    systemctl daemon-reload
    echo ""
    echo "Augias has been installed successfully."
    echo ""
    echo "To start the service:"
    echo "  sudo systemctl start augias"
    echo ""
    echo "To enable the service on boot:"
    echo "  sudo systemctl enable augias"
    echo ""
    echo "Configuration: /etc/augias/augias.env"
    echo ""
fi
