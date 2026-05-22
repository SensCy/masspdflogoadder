# PDF Header Batch Tool

This desktop tool takes one or more source PDFs and a folder of logo images, then creates stamped output PDFs with the image placed in the header area on every page.

Examples:

- 1 PDF + 50 logos = 50 output PDFs
- 10 PDFs + 1 selected logo = 10 output PDFs
- 10 PDFs + 50 logos = 500 output PDFs

## What It Does

- Select one or more source PDFs.
- Select a folder containing `.png`, `.jpg`, or `.jpeg` logo files.
- Choose whether to use all logos in the folder or just the highlighted logo.
- Choose the header placement and logo size once.
- Optionally create a single preview PDF using the highlighted PDF and highlighted logo.
- Generate one stamped PDF for every selected PDF/logo combination.

## Install

```powershell
python -m pip install -r requirements.txt
```

## Run

Double-click `run_header_tool.bat` or run:

```powershell
python SensCyPDFLOGOapp.py
```

## Workflow

1. Choose the source PDF.
2. Choose the folder that stores your logos.
3. Choose an output folder.
4. Pick a batch mode:
   - `Apply every logo in the folder to every selected PDF`
   - `Apply only the highlighted logo to every selected PDF`
5. Set the header placement.
6. Click `Create Preview PDF` to test the highlighted PDF/logo pair.
7. Click `Generate PDFs` to create the full batch.

## Placement Notes

- `Horizontal anchor` controls whether the logo box is aligned from the left edge, the page center, or the right edge.
- `X offset (in)` is measured in inches. With `center`, it shifts the logo box left or right from the centered position.
- `Top offset (in)` is the distance from the top edge of the page.
- `Logo width (in)` and `Logo height (in)` define the logo box size.
- `Keep aspect ratio` fits the image inside the logo box without stretching it.

## Output Naming

Generated files use this pattern:

```text
logoName_sourcePdfName.pdf
```

Preview files use this pattern:

```text
preview_logoName_sourcePdfName.pdf
```

Example:

```text
Google_UserAccessControlPolicy.pdf
AmazonWebService_UserAccessControlPolicy.pdf
```

If a file name already exists, the tool adds a numeric suffix so it does not overwrite the earlier file.
