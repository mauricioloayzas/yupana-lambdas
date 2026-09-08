#!/bin/bash
set -e

STAGE=${STAGE:-dev}
REGION=${AWS_DEFAULT_REGION:-us-east-1}
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(dirname "$SCRIPT_DIR")"
BOB_DIR="$(cd "$SCRIPT_DIR/../../../bob-contruye" && pwd)"

LAYER_NAME="mauloasan-shared-${STAGE}-vendor" \
ARN_OUTPUT_FILE="$BACKEND_DIR/.vendor-layer-arn" \
STAGE="$STAGE" \
AWS_DEFAULT_REGION="$REGION" \
bash "$BOB_DIR/scripts/build-layer.sh"
