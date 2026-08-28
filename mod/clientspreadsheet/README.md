# Moodle Client Spreadsheet Activity

`mod_clientspreadsheet` is a Moodle activity module for collecting client-submitted spreadsheets.

## What it does

- Shows clients a two-column activity page: upload form on the left, example spreadsheet preview on the right.
- Provides a generated CSV example download based on the activity's required columns.
- Accepts one `.xlsx` or `.csv` upload per submission.
- Validates that the spreadsheet has the required headers, no duplicate headers, at least one data row, and values in required columns.
- Stores valid submissions for staff review.
- Gives clients a confirmation page asking them to allow 24 hours for processing.
- Lets site admins download submitted sheets and remove completed requests after a confirmation step.

Automatic Moodle user creation/import is intentionally not included yet. The review page is ready for that workflow to be added later.

## Install

1. Copy this folder to your Moodle site as `mod/clientspreadsheet`.
2. Visit **Site administration > Notifications** to install the plugin.
3. Add a **Client spreadsheet** activity to a course.
4. Configure the required columns. The default columns are:

```text
email
first name
last name
```

## Staff Workflow

1. Open the activity.
2. Use **View submissions** as a Moodle site admin.
3. Download submitted spreadsheets.
4. Upload the users into Moodle using your current user-upload process.
5. Click **Completed**, confirm the action, and the request is removed from the list.

## Client Workflow

1. Open the activity.
2. Compare their sheet to the visual example.
3. Download the generated example CSV if needed.
4. Upload a `.xlsx` or `.csv`.
5. Fix any validation errors shown on screen, or see the 24-hour confirmation page after a valid submission.
