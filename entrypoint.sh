#!/bin/bash
set -e

# Path to the Docker socket
SOCKET="/var/run/docker.sock"

if [ -S "$SOCKET" ]; then
    # Get group ID of the socket
    SOCKET_GID=$(stat -c '%g' "$SOCKET")
    
    # Check if a group with this GID already exists in /etc/group
    GROUP_NAME=$(getent group "$SOCKET_GID" | cut -d: -f1)
    
    if [ -z "$GROUP_NAME" ]; then
        # Group doesn't exist, create it
        GROUP_NAME="host-docker"
        groupadd -g "$SOCKET_GID" "$GROUP_NAME"
        echo "Created group $GROUP_NAME with GID $SOCKET_GID"
    else
        echo "Found existing group $GROUP_NAME with GID $SOCKET_GID"
    fi
    
    # Add www-data user to this group
    usermod -aG "$GROUP_NAME" www-data
    echo "Added user www-data to group $GROUP_NAME"
else
    echo "Warning: Docker socket not found at $SOCKET. Docker commands might fail."
fi

# Execute Apache in foreground (replaces current process)
exec apache2-foreground
