"""
Signature image cleaner.

Reads an arbitrary signature photo (different cameras, lighting, paper colors,
pen colors) and produces a clean black-on-white PNG containing only the
signature strokes.

Usage:
    python clean_signature.py <input_path> <output_path>

Exit codes:
    0 — success
    1 — bad arguments
    2 — could not read input
    3 — could not write output
    4 — empty result (no signature detected)
"""

import sys

import cv2
import numpy as np


MAX_DIM = 1600
ADAPTIVE_BLOCK = 35
ADAPTIVE_C = 11
MIN_COMPONENT_AREA = 25
CROP_PADDING = 20


def clean_signature(input_path: str, output_path: str) -> int:
    img = cv2.imread(input_path, cv2.IMREAD_COLOR)
    if img is None:
        return 2

    gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)

    h, w = gray.shape
    if max(h, w) > MAX_DIM:
        scale = MAX_DIM / float(max(h, w))
        gray = cv2.resize(
            gray,
            (int(w * scale), int(h * scale)),
            interpolation=cv2.INTER_AREA,
        )

    blurred = cv2.GaussianBlur(gray, (3, 3), 0)

    binary = cv2.adaptiveThreshold(
        blurred,
        255,
        cv2.ADAPTIVE_THRESH_GAUSSIAN_C,
        cv2.THRESH_BINARY_INV,
        ADAPTIVE_BLOCK,
        ADAPTIVE_C,
    )

    kernel = np.ones((2, 2), np.uint8)
    opened = cv2.morphologyEx(binary, cv2.MORPH_OPEN, kernel)

    num_labels, labels, stats, _ = cv2.connectedComponentsWithStats(
        opened, connectivity=8
    )
    mask = np.zeros_like(opened)
    for i in range(1, num_labels):
        if stats[i, cv2.CC_STAT_AREA] >= MIN_COMPONENT_AREA:
            mask[labels == i] = 255

    coords = cv2.findNonZero(mask)
    if coords is None:
        return 4

    x, y, cw, ch = cv2.boundingRect(coords)
    x0 = max(0, x - CROP_PADDING)
    y0 = max(0, y - CROP_PADDING)
    x1 = min(mask.shape[1], x + cw + CROP_PADDING)
    y1 = min(mask.shape[0], y + ch + CROP_PADDING)
    mask = mask[y0:y1, x0:x1]

    final = cv2.bitwise_not(mask)

    ok = cv2.imwrite(output_path, final, [cv2.IMWRITE_PNG_COMPRESSION, 6])
    return 0 if ok else 3


def main() -> int:
    if len(sys.argv) < 3:
        print("usage: clean_signature.py <input> <output>", file=sys.stderr)
        return 1
    return clean_signature(sys.argv[1], sys.argv[2])


if __name__ == "__main__":
    sys.exit(main())
