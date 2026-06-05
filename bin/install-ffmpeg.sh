#!/bin/bash
set -e

echo "Ensuring bin directory exists..."
mkdir -p bin
cd bin

echo "Detecting architecture..."
ARCH=$(uname -m)
if [ "$ARCH" = "aarch64" ] || [ "$ARCH" = "arm64" ]; then
  echo "Detected ARM64"
  URL="https://johnvansickle.com/ffmpeg/releases/ffmpeg-release-arm64-static.tar.xz"
else
  echo "Detected AMD64/x86_64"
  URL="https://johnvansickle.com/ffmpeg/releases/ffmpeg-release-amd64-static.tar.xz"
fi

echo "Downloading FFmpeg static build..."
curl -O -L $URL

echo "Extracting FFmpeg..."
tar -xf ffmpeg-release-*-static.tar.xz --strip-components=1
rm ffmpeg-release-*-static.tar.xz

chmod +x ffmpeg ffprobe
echo "FFmpeg and FFprobe installed successfully at $(pwd)"
