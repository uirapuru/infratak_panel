#!/bin/bash
set -euxo pipefail

export DEBIAN_FRONTEND=noninteractive

apt-get update
apt-get install -y --no-install-recommends \
  ca-certificates \
  curl \
  gnupg \
  lsb-release \
  docker.io \
  docker-compose-v2

systemctl enable docker
systemctl start docker

# Allow ubuntu user to run docker without sudo after next login
usermod -aG docker ubuntu || true

# Basic diagnostics for cloud-init logs
docker --version || true
docker compose version || true
