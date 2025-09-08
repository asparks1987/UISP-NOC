#!/bin/bash
# Multi-arch build script for x86_64 and ARM (armv7, arm64)
# Usage: ./build-multiarch.sh <dockerhub-username>/<repo>:<tag>

IMAGE=$1

if [ -z "$IMAGE" ]; then
  echo "Usage: $0 <dockerhub-username>/<repo>:<tag>"
  exit 1
fi

# Ensure buildx is set up
docker buildx create --use --name multiarch-builder || docker buildx use multiarch-builder

# Build and push multi-arch image
docker buildx build --platform linux/amd64,linux/arm64,linux/arm/v7 -t $IMAGE --push .
