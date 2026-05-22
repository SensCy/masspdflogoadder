from __future__ import annotations

from collections.abc import Sequence
from dataclasses import dataclass
from pathlib import Path
import re
from typing import Callable

import fitz
from PIL import Image


SUPPORTED_IMAGE_EXTENSIONS = {".png", ".jpg", ".jpeg"}


@dataclass(slots=True)
class PlacementConfig:
    x_offset_in: float = 0.0
    y_offset_in: float = 0.35
    box_width_in: float = 1.75
    box_height_in: float = 0.75
    anchor: str = "center"
    keep_aspect: bool = True

    def validate(self) -> None:
        if self.anchor not in {"left", "center", "right"}:
            raise ValueError("Anchor must be left, center, or right.")
        if self.box_width_in <= 0:
            raise ValueError("Logo width must be greater than 0.")
        if self.box_height_in <= 0:
            raise ValueError("Logo height must be greater than 0.")
        if self.y_offset_in < 0:
            raise ValueError("Top offset cannot be negative.")


def inches_to_points(value: float) -> float:
    return value * 72.0


def list_logo_files(logos_dir: str | Path) -> list[Path]:
    directory = Path(logos_dir)
    if not directory.exists():
        raise FileNotFoundError(f"Logo folder not found: {directory}")
    if not directory.is_dir():
        raise NotADirectoryError(f"Logo path is not a folder: {directory}")

    return sorted(
        path
        for path in directory.iterdir()
        if path.is_file() and path.suffix.lower() in SUPPORTED_IMAGE_EXTENSIONS
    )


def normalize_pdf_paths(pdf_paths: Sequence[str | Path]) -> list[Path]:
    normalized_paths: list[Path] = []
    for pdf_path in pdf_paths:
        path = Path(pdf_path)
        if not path.exists() or path.suffix.lower() != ".pdf":
            raise FileNotFoundError(f"PDF not found or invalid: {path}")
        normalized_paths.append(path)

    if not normalized_paths:
        raise ValueError("No PDF files were selected.")

    return normalized_paths


def normalize_logo_paths(logo_paths: Sequence[str | Path]) -> list[Path]:
    normalized_paths: list[Path] = []
    for logo_path in logo_paths:
        path = Path(logo_path)
        if not path.exists() or path.suffix.lower() not in SUPPORTED_IMAGE_EXTENSIONS:
            raise FileNotFoundError(f"Logo file not found or invalid: {path}")
        normalized_paths.append(path)

    if not normalized_paths:
        raise ValueError("No PNG/JPG logo files were selected.")

    return normalized_paths


def _slugify(value: str) -> str:
    cleaned = re.sub(r"[^A-Za-z0-9._-]+", "_", value).strip("._")
    return cleaned or "file"


def _next_available_path(path: Path) -> Path:
    if not path.exists():
        return path

    counter = 2
    while True:
        candidate = path.with_name(f"{path.stem}_{counter}{path.suffix}")
        if not candidate.exists():
            return candidate
        counter += 1


def build_output_path(
    pdf_path: str | Path,
    logo_path: str | Path,
    output_dir: str | Path,
    *,
    prefix: str | None = None,
) -> Path:
    pdf_file = Path(pdf_path)
    logo_file = Path(logo_path)
    output_folder = Path(output_dir)

    base_name = f"{_slugify(logo_file.stem)}_{_slugify(pdf_file.stem)}"
    if prefix:
        base_name = f"{_slugify(prefix)}_{base_name}"

    return _next_available_path(output_folder / f"{base_name}.pdf")


def _resolve_box(page_rect: fitz.Rect, placement: PlacementConfig) -> fitz.Rect:
    box_width = inches_to_points(placement.box_width_in)
    box_height = inches_to_points(placement.box_height_in)
    x_offset = inches_to_points(placement.x_offset_in)
    y_offset = inches_to_points(placement.y_offset_in)

    if placement.anchor == "left":
        x0 = x_offset
    elif placement.anchor == "center":
        x0 = ((page_rect.width - box_width) / 2.0) + x_offset
    else:
        x0 = page_rect.width - box_width - x_offset

    y0 = y_offset
    box = fitz.Rect(x0, y0, x0 + box_width, y0 + box_height)

    if box.x0 < 0 or box.y0 < 0 or box.x1 > page_rect.width or box.y1 > page_rect.height:
        raise ValueError(
            "The header placement falls outside the PDF page. "
            "Adjust the position or size and try again."
        )

    return box


def _fit_image_rect(
    box: fitz.Rect,
    image_size: tuple[int, int],
    placement: PlacementConfig,
) -> fitz.Rect:
    if not placement.keep_aspect:
        return box

    image_width, image_height = image_size
    if image_width <= 0 or image_height <= 0:
        raise ValueError("Logo image has invalid dimensions.")

    scale = min(box.width / image_width, box.height / image_height)
    scaled_width = image_width * scale
    scaled_height = image_height * scale

    if placement.anchor == "left":
        x0 = box.x0
    elif placement.anchor == "center":
        x0 = box.x0 + ((box.width - scaled_width) / 2.0)
    else:
        x0 = box.x1 - scaled_width

    y0 = box.y0
    return fitz.Rect(x0, y0, x0 + scaled_width, y0 + scaled_height)


def stamp_pdf_with_logo(
    pdf_path: str | Path,
    logo_path: str | Path,
    output_path: str | Path,
    placement: PlacementConfig,
) -> Path:
    source_pdf = Path(pdf_path)
    logo_file = Path(logo_path)
    destination_pdf = Path(output_path)

    if not source_pdf.exists():
        raise FileNotFoundError(f"PDF not found: {source_pdf}")
    if not logo_file.exists():
        raise FileNotFoundError(f"Logo file not found: {logo_file}")

    placement.validate()
    destination_pdf.parent.mkdir(parents=True, exist_ok=True)

    logo_bytes = logo_file.read_bytes()
    with Image.open(logo_file) as image:
        image_size = image.size

    document = fitz.open(str(source_pdf))
    if document.needs_pass:
        document.close()
        raise ValueError("This PDF is password protected and cannot be processed.")

    image_xref = 0
    try:
        for page in document:
            box = _resolve_box(page.rect, placement)
            image_rect = _fit_image_rect(box, image_size, placement)
            image_xref = page.insert_image(
                image_rect,
                stream=logo_bytes,
                overlay=True,
                xref=image_xref,
                keep_proportion=False,
            )

        document.save(str(destination_pdf), garbage=4, deflate=True)
    finally:
        document.close()

    return destination_pdf


def process_pdf_logo_pairs(
    pdf_paths: Sequence[str | Path],
    logo_paths: Sequence[str | Path],
    output_dir: str | Path,
    placement: PlacementConfig,
    progress_callback: Callable[[int, int, Path, Path, Path], None] | None = None,
) -> list[Path]:
    normalized_pdfs = normalize_pdf_paths(pdf_paths)
    normalized_logos = normalize_logo_paths(logo_paths)

    output_folder = Path(output_dir)
    output_folder.mkdir(parents=True, exist_ok=True)

    results: list[Path] = []
    total = len(normalized_pdfs) * len(normalized_logos)
    current = 0

    for pdf_path in normalized_pdfs:
        for logo_path in normalized_logos:
            current += 1
            output_path = build_output_path(pdf_path, logo_path, output_folder)
            stamped_pdf = stamp_pdf_with_logo(pdf_path, logo_path, output_path, placement)
            results.append(stamped_pdf)

            if progress_callback:
                progress_callback(current, total, pdf_path, logo_path, stamped_pdf)

    return results


def process_batch(
    pdf_path: str | Path,
    logos_dir: str | Path,
    output_dir: str | Path,
    placement: PlacementConfig,
    progress_callback: Callable[[int, int, Path, Path, Path], None] | None = None,
) -> list[Path]:
    logo_files = list_logo_files(logos_dir)
    return process_pdf_logo_pairs(
        [pdf_path],
        logo_files,
        output_dir,
        placement,
        progress_callback=progress_callback,
    )


def process_pdfs_with_logo(
    pdf_paths: Sequence[str | Path],
    logo_path: str | Path,
    output_dir: str | Path,
    placement: PlacementConfig,
    progress_callback: Callable[[int, int, Path, Path, Path], None] | None = None,
) -> list[Path]:
    return process_pdf_logo_pairs(
        pdf_paths,
        [logo_path],
        output_dir,
        placement,
        progress_callback=progress_callback,
    )
