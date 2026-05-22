from __future__ import annotations

from pathlib import Path
import tempfile
import unittest

import fitz
from PIL import Image

from pdf_header_tool import (
    PlacementConfig,
    list_logo_files,
    process_batch,
    process_pdfs_with_logo,
)


class PdfHeaderToolTests(unittest.TestCase):
    def _create_pdf(self, path: Path, title: str, page_count: int = 2) -> None:
        document = fitz.open()
        for page_number in range(page_count):
            page = document.new_page()
            page.insert_text((72, 72), f"{title} page {page_number + 1}")
        document.save(path)
        document.close()

    def test_list_logo_files_filters_supported_extensions(self) -> None:
        with tempfile.TemporaryDirectory() as temp_dir:
            temp_path = Path(temp_dir)
            (temp_path / "keep.png").write_bytes(b"png")
            (temp_path / "keep.jpg").write_bytes(b"jpg")
            (temp_path / "skip.txt").write_text("ignore", encoding="utf-8")

            logos = list_logo_files(temp_path)
            self.assertEqual([logo.name for logo in logos], ["keep.jpg", "keep.png"])

    def test_process_batch_creates_one_pdf_per_logo(self) -> None:
        with tempfile.TemporaryDirectory() as temp_dir:
            temp_path = Path(temp_dir)
            source_pdf = temp_path / "UserAccessControlPolicy.pdf"
            logo_dir = temp_path / "logos"
            output_dir = temp_path / "output"
            logo_dir.mkdir()

            self._create_pdf(source_pdf, "UserAccessControlPolicy")

            for name, color in [
                ("Google.png", "red"),
                ("AmazonWebService.jpg", "blue"),
                ("Gamma.png", "green"),
            ]:
                image_path = logo_dir / name
                Image.new("RGB", (160, 80), color=color).save(image_path)

            results = process_batch(
                source_pdf,
                logo_dir,
                output_dir,
                PlacementConfig(anchor="center", y_offset_in=0.2, box_width_in=1.8, box_height_in=0.7),
            )

            self.assertEqual(len(results), 3)
            self.assertEqual(
                {output_pdf.name for output_pdf in results},
                {
                    "AmazonWebService_UserAccessControlPolicy.pdf",
                    "Gamma_UserAccessControlPolicy.pdf",
                    "Google_UserAccessControlPolicy.pdf",
                },
            )
            for output_pdf in results:
                self.assertTrue(output_pdf.exists(), msg=f"Expected output file: {output_pdf}")
                stamped_document = fitz.open(output_pdf)
                self.assertEqual(stamped_document.page_count, 2)
                for page in stamped_document:
                    self.assertGreaterEqual(len(page.get_images(full=True)), 1)
                stamped_document.close()

    def test_process_pdfs_with_logo_creates_one_pdf_per_source_pdf(self) -> None:
        with tempfile.TemporaryDirectory() as temp_dir:
            temp_path = Path(temp_dir)
            output_dir = temp_path / "output"
            logo_path = temp_path / "Google.png"
            pdf_paths = [
                temp_path / "AccessPolicy.pdf",
                temp_path / "IncidentResponse.pdf",
            ]

            for pdf_path in pdf_paths:
                self._create_pdf(pdf_path, pdf_path.stem, page_count=3)

            Image.new("RGB", (160, 80), color="red").save(logo_path)

            results = process_pdfs_with_logo(
                pdf_paths,
                logo_path,
                output_dir,
                PlacementConfig(anchor="center", y_offset_in=0.2, box_width_in=1.8, box_height_in=0.7),
            )

            self.assertEqual(len(results), 2)
            self.assertEqual(
                {output_pdf.name for output_pdf in results},
                {
                    "Google_AccessPolicy.pdf",
                    "Google_IncidentResponse.pdf",
                },
            )
            for output_pdf in results:
                self.assertTrue(output_pdf.exists(), msg=f"Expected output file: {output_pdf}")
                stamped_document = fitz.open(output_pdf)
                self.assertEqual(stamped_document.page_count, 3)
                for page in stamped_document:
                    self.assertGreaterEqual(len(page.get_images(full=True)), 1)
                stamped_document.close()


if __name__ == "__main__":
    unittest.main()
