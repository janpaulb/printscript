#!/bin/bash
# Start the PrintScript web server
set -e
PORT=${PORT:-5000}
echo "Starting PrintScript on http://localhost:$PORT"
python3 app.py
