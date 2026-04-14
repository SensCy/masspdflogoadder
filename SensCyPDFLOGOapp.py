from __future__ import annotations

import json
from pathlib import Path
import queue
import threading
import tkinter as tk
from tkinter import filedialog, messagebox, ttk

import fitz

from pdf_header_tool import (
    PlacementConfig,
    build_output_path,
    list_logo_files,
    process_batch,
    stamp_pdf_with_logo,
)


APP_DIR = Path(__file__).resolve().parent
SETTINGS_PATH = APP_DIR / "header_tool_settings.json"


class HeaderBatchApp:
    def __init__(self, root: tk.Tk) -> None:
        self.root = root
        self.root.title("PDF Header Batch Tool")
        self.root.minsize(900, 650)

        self.message_queue: queue.Queue[tuple] = queue.Queue()
        self.worker: threading.Thread | None = None

        self.pdf_path_var = tk.StringVar()
        self.logo_dir_var = tk.StringVar()
        self.output_dir_var = tk.StringVar()
        self.anchor_var = tk.StringVar(value="center")
        self.x_offset_var = tk.StringVar(value="0.0")
        self.y_offset_var = tk.StringVar(value="0.35")
        self.width_var = tk.StringVar(value="1.75")
        self.height_var = tk.StringVar(value="0.75")
        self.keep_aspect_var = tk.BooleanVar(value=True)
        self.status_var = tk.StringVar(value="Select a PDF and a folder of logos to begin.")
        self.pdf_info_var = tk.StringVar(value="No PDF selected.")
        self.logo_count_var = tk.StringVar(value="No logo folder selected.")

        self.path_entries: list[ttk.Entry] = []
        self.value_entries: list[ttk.Entry] = []
        self.action_buttons: list[ttk.Button] = []
        self.placement_controls: list[tk.Widget] = []

        self._load_settings()
        self._build_ui()
        self._refresh_logo_list()
        self._refresh_pdf_info()
        self.root.protocol("WM_DELETE_WINDOW", self._on_close)
        self.root.after(120, self._poll_queue)

    def _build_ui(self) -> None:
        self.root.columnconfigure(0, weight=1)
        self.root.rowconfigure(0, weight=1)

        container = ttk.Frame(self.root, padding=18)
        container.grid(row=0, column=0, sticky="nsew")
        container.columnconfigure(1, weight=1)
        container.rowconfigure(3, weight=1)

        self._build_path_row(
            container,
            row=0,
            label="Source PDF",
            variable=self.pdf_path_var,
            button_text="Browse PDF",
            command=self._choose_pdf,
        )
        self._build_path_row(
            container,
            row=1,
            label="Logo Folder",
            variable=self.logo_dir_var,
            button_text="Browse Folder",
            command=self._choose_logo_folder,
        )
        self._build_path_row(
            container,
            row=2,
            label="Output Folder",
            variable=self.output_dir_var,
            button_text="Browse Folder",
            command=self._choose_output_folder,
        )

        summary_frame = ttk.LabelFrame(container, text="Selection Summary", padding=12)
        summary_frame.grid(row=3, column=0, columnspan=3, sticky="nsew", pady=(16, 12))
        summary_frame.columnconfigure(0, weight=1)
        summary_frame.rowconfigure(2, weight=1)

        ttk.Label(summary_frame, textvariable=self.pdf_info_var).grid(
            row=0, column=0, sticky="w"
        )
        ttk.Label(summary_frame, textvariable=self.logo_count_var).grid(
            row=1, column=0, sticky="w", pady=(6, 10)
        )

        list_frame = ttk.Frame(summary_frame)
        list_frame.grid(row=2, column=0, sticky="nsew")
        list_frame.columnconfigure(0, weight=1)
        list_frame.rowconfigure(0, weight=1)

        self.logo_listbox = tk.Listbox(list_frame, height=10, exportselection=False)
        self.logo_listbox.grid(row=0, column=0, sticky="nsew")
        logo_scrollbar = ttk.Scrollbar(list_frame, orient="vertical", command=self.logo_listbox.yview)
        logo_scrollbar.grid(row=0, column=1, sticky="ns")
        self.logo_listbox.configure(yscrollcommand=logo_scrollbar.set)

        placement_frame = ttk.LabelFrame(container, text="Header Placement", padding=12)
        placement_frame.grid(row=4, column=0, columnspan=3, sticky="ew")
        placement_frame.columnconfigure(1, weight=1)
        placement_frame.columnconfigure(3, weight=1)

        ttk.Label(placement_frame, text="Horizontal anchor").grid(row=0, column=0, sticky="w")
        anchor_box = ttk.Combobox(
            placement_frame,
            textvariable=self.anchor_var,
            state="readonly",
            values=("left", "center", "right"),
            width=12,
        )
        anchor_box.grid(row=0, column=1, sticky="ew", padx=(10, 18))
        self.placement_controls.append(anchor_box)

        self._build_value_row(
            placement_frame,
            row=0,
            column=2,
            label="X offset (in)",
            variable=self.x_offset_var,
        )
        self._build_value_row(
            placement_frame,
            row=1,
            column=0,
            label="Top offset (in)",
            variable=self.y_offset_var,
        )
        self._build_value_row(
            placement_frame,
            row=1,
            column=2,
            label="Logo width (in)",
            variable=self.width_var,
        )
        self._build_value_row(
            placement_frame,
            row=2,
            column=0,
            label="Logo height (in)",
            variable=self.height_var,
        )

        keep_aspect = ttk.Checkbutton(
            placement_frame,
            text="Keep the image aspect ratio inside the logo box",
            variable=self.keep_aspect_var,
        )
        keep_aspect.grid(row=2, column=2, columnspan=2, sticky="w", padx=(10, 0))
        self.placement_controls.append(keep_aspect)

        ttk.Label(
            placement_frame,
            text=(
                "Tip: use Preview first to test one logo before generating the full batch. "
                "For center anchor, X offset nudges left or right from the page center."
            ),
            wraplength=780,
            foreground="#555555",
        ).grid(row=3, column=0, columnspan=4, sticky="w", pady=(10, 0))

        action_frame = ttk.Frame(container)
        action_frame.grid(row=5, column=0, columnspan=3, sticky="ew", pady=(16, 0))
        action_frame.columnconfigure(3, weight=1)

        preview_button = ttk.Button(
            action_frame,
            text="Create Preview PDF",
            command=self._start_preview,
        )
        preview_button.grid(row=0, column=0, padx=(0, 8))

        batch_button = ttk.Button(
            action_frame,
            text="Generate All PDFs",
            command=self._start_batch,
        )
        batch_button.grid(row=0, column=1, padx=(0, 8))

        refresh_button = ttk.Button(
            action_frame,
            text="Refresh Logos",
            command=self._refresh_logo_list,
        )
        refresh_button.grid(row=0, column=2, padx=(0, 16))

        self.action_buttons.extend([preview_button, batch_button, refresh_button])

        self.progress = ttk.Progressbar(action_frame, mode="determinate", length=320)
        self.progress.grid(row=0, column=3, sticky="ew", padx=(0, 16))

        ttk.Label(action_frame, textvariable=self.status_var).grid(row=1, column=0, columnspan=4, sticky="w", pady=(10, 0))

    def _build_path_row(
        self,
        parent: ttk.Frame,
        *,
        row: int,
        label: str,
        variable: tk.StringVar,
        button_text: str,
        command,
    ) -> None:
        ttk.Label(parent, text=label).grid(row=row, column=0, sticky="w", pady=4)
        entry = ttk.Entry(parent, textvariable=variable)
        entry.grid(row=row, column=1, sticky="ew", padx=(12, 10), pady=4)
        button = ttk.Button(parent, text=button_text, command=command)
        button.grid(row=row, column=2, sticky="ew", pady=4)

        self.path_entries.append(entry)
        self.action_buttons.append(button)

    def _build_value_row(
        self,
        parent: ttk.LabelFrame,
        *,
        row: int,
        column: int,
        label: str,
        variable: tk.StringVar,
    ) -> None:
        ttk.Label(parent, text=label).grid(row=row, column=column, sticky="w", pady=4)
        entry = ttk.Entry(parent, textvariable=variable, width=14)
        entry.grid(row=row, column=column + 1, sticky="ew", padx=(10, 18), pady=4)
        self.value_entries.append(entry)

    def _choose_pdf(self) -> None:
        path = filedialog.askopenfilename(
            title="Select Source PDF",
            filetypes=[("PDF files", "*.pdf")],
        )
        if not path:
            return

        self.pdf_path_var.set(path)
        if not self.output_dir_var.get():
            suggested_output = str(Path(path).with_name(f"{Path(path).stem}_header_output"))
            self.output_dir_var.set(suggested_output)
        self._refresh_pdf_info()
        self._save_settings()

    def _choose_logo_folder(self) -> None:
        path = filedialog.askdirectory(title="Select Logo Folder")
        if not path:
            return

        self.logo_dir_var.set(path)
        self._refresh_logo_list()
        self._save_settings()

    def _choose_output_folder(self) -> None:
        path = filedialog.askdirectory(title="Select Output Folder")
        if not path:
            return

        self.output_dir_var.set(path)
        self._save_settings()

    def _refresh_pdf_info(self) -> None:
        pdf_path = self.pdf_path_var.get().strip()
        if not pdf_path:
            self.pdf_info_var.set("No PDF selected.")
            return

        pdf_file = Path(pdf_path)
        if not pdf_file.exists():
            self.pdf_info_var.set("Selected PDF does not exist.")
            return

        try:
            with fitz.open(str(pdf_file)) as document:
                self.pdf_info_var.set(
                    f"Source PDF: {pdf_file.name} | {document.page_count} pages"
                )
        except Exception:
            self.pdf_info_var.set(f"Source PDF: {pdf_file.name}")

    def _refresh_logo_list(self) -> None:
        self.logo_listbox.delete(0, tk.END)
        logo_dir = self.logo_dir_var.get().strip()
        if not logo_dir:
            self.logo_count_var.set("No logo folder selected.")
            return

        try:
            logos = list_logo_files(logo_dir)
        except Exception as exc:
            self.logo_count_var.set(f"Logo folder error: {exc}")
            return

        if not logos:
            self.logo_count_var.set("No PNG/JPG logo files found in the selected folder.")
            return

        for logo in logos:
            self.logo_listbox.insert(tk.END, logo.name)

        self.logo_listbox.selection_clear(0, tk.END)
        self.logo_listbox.selection_set(0)
        self.logo_count_var.set(f"Found {len(logos)} logo files.")

    def _collect_inputs(self) -> tuple[Path, Path, Path, PlacementConfig]:
        pdf_text = self.pdf_path_var.get().strip()
        logo_dir_text = self.logo_dir_var.get().strip()
        output_dir_text = self.output_dir_var.get().strip()

        if not pdf_text:
            raise ValueError("Select a source PDF file.")
        if not logo_dir_text:
            raise ValueError("Select a logo folder.")
        if not output_dir_text:
            raise ValueError("Select an output folder.")

        pdf_path = Path(pdf_text)
        logo_dir = Path(logo_dir_text)
        output_dir = Path(output_dir_text)

        if not pdf_path.exists() or pdf_path.suffix.lower() != ".pdf":
            raise ValueError("Select a valid PDF file.")
        if not logo_dir.exists() or not logo_dir.is_dir():
            raise ValueError("Select a valid logo folder.")

        placement = PlacementConfig(
            anchor=self.anchor_var.get().strip().lower(),
            x_offset_in=float(self.x_offset_var.get().strip()),
            y_offset_in=float(self.y_offset_var.get().strip()),
            box_width_in=float(self.width_var.get().strip()),
            box_height_in=float(self.height_var.get().strip()),
            keep_aspect=self.keep_aspect_var.get(),
        )
        placement.validate()
        return pdf_path, logo_dir, output_dir, placement

    def _selected_logo_path(self, logo_dir: Path) -> Path:
        logos = list_logo_files(logo_dir)
        if not logos:
            raise ValueError("No PNG/JPG logo files were found in the selected folder.")

        selection = self.logo_listbox.curselection()
        if not selection:
            return logos[0]
        return logos[selection[0]]

    def _start_preview(self) -> None:
        if self.worker and self.worker.is_alive():
            return

        try:
            pdf_path, logo_dir, output_dir, placement = self._collect_inputs()
            logo_path = self._selected_logo_path(logo_dir)
        except Exception as exc:
            messagebox.showerror("Preview Error", str(exc))
            return

        self._save_settings()
        self._set_busy(True)
        self.progress.configure(mode="indeterminate")
        self.progress.start(10)
        self.status_var.set(f"Creating preview using {logo_path.name}...")

        self.worker = threading.Thread(
            target=self._preview_worker,
            args=(pdf_path, logo_path, output_dir, placement),
            daemon=True,
        )
        self.worker.start()

    def _preview_worker(
        self,
        pdf_path: Path,
        logo_path: Path,
        output_dir: Path,
        placement: PlacementConfig,
    ) -> None:
        try:
            output_path = build_output_path(
                pdf_path,
                logo_path,
                output_dir,
                prefix="preview",
            )
            stamped = stamp_pdf_with_logo(pdf_path, logo_path, output_path, placement)
            self.message_queue.put(("preview_complete", stamped))
        except Exception as exc:
            self.message_queue.put(("error", str(exc)))

    def _start_batch(self) -> None:
        if self.worker and self.worker.is_alive():
            return

        try:
            pdf_path, logo_dir, output_dir, placement = self._collect_inputs()
            logo_total = len(list_logo_files(logo_dir))
            if logo_total == 0:
                raise ValueError("No PNG/JPG logo files were found in the selected folder.")
        except Exception as exc:
            messagebox.showerror("Batch Error", str(exc))
            return

        self._save_settings()
        self._set_busy(True)
        self.progress.stop()
        self.progress.configure(mode="determinate", maximum=logo_total, value=0)
        self.status_var.set(f"Generating {logo_total} PDFs...")

        self.worker = threading.Thread(
            target=self._batch_worker,
            args=(pdf_path, logo_dir, output_dir, placement),
            daemon=True,
        )
        self.worker.start()

    def _batch_worker(
        self,
        pdf_path: Path,
        logo_dir: Path,
        output_dir: Path,
        placement: PlacementConfig,
    ) -> None:
        try:
            outputs = process_batch(
                pdf_path,
                logo_dir,
                output_dir,
                placement,
                progress_callback=self._queue_progress,
            )
            self.message_queue.put(("batch_complete", outputs))
        except Exception as exc:
            self.message_queue.put(("error", str(exc)))

    def _queue_progress(self, current: int, total: int, logo_path: Path, output_path: Path) -> None:
        self.message_queue.put(("progress", current, total, logo_path.name, str(output_path)))

    def _set_busy(self, is_busy: bool) -> None:
        state = "disabled" if is_busy else "normal"
        for entry in self.path_entries + self.value_entries:
            entry.configure(state=state)

        for control in self.placement_controls:
            control.configure(state=state)

        for button in self.action_buttons:
            button.configure(state=state)

    def _poll_queue(self) -> None:
        while True:
            try:
                event = self.message_queue.get_nowait()
            except queue.Empty:
                break

            event_type = event[0]
            if event_type == "progress":
                _, current, total, logo_name, _ = event
                self.progress.configure(maximum=total, value=current)
                self.status_var.set(f"Processed {current}/{total}: {logo_name}")
            elif event_type == "preview_complete":
                _, output_path = event
                self.progress.stop()
                self.progress.configure(mode="determinate", value=0)
                self._set_busy(False)
                self.status_var.set(f"Preview created: {Path(output_path).name}")
                messagebox.showinfo("Preview Ready", f"Preview PDF created:\n{output_path}")
            elif event_type == "batch_complete":
                _, outputs = event
                self.progress.stop()
                self._set_busy(False)
                self.status_var.set(f"Finished. Created {len(outputs)} PDF files.")
                messagebox.showinfo(
                    "Batch Complete",
                    f"Created {len(outputs)} PDFs in:\n{Path(outputs[0]).parent if outputs else self.output_dir_var.get()}",
                )
            elif event_type == "error":
                _, error_message = event
                self.progress.stop()
                self.progress.configure(mode="determinate", value=0)
                self._set_busy(False)
                self.status_var.set("The last run stopped with an error.")
                messagebox.showerror("Processing Error", error_message)

        self.root.after(120, self._poll_queue)

    def _load_settings(self) -> None:
        if not SETTINGS_PATH.exists():
            return

        try:
            settings = json.loads(SETTINGS_PATH.read_text(encoding="utf-8"))
        except Exception:
            return

        self.pdf_path_var.set(settings.get("pdf_path", ""))
        self.logo_dir_var.set(settings.get("logo_dir", ""))
        self.output_dir_var.set(settings.get("output_dir", ""))
        self.anchor_var.set(settings.get("anchor", "center"))
        self.x_offset_var.set(str(settings.get("x_offset_in", "0.0")))
        self.y_offset_var.set(str(settings.get("y_offset_in", "0.35")))
        self.width_var.set(str(settings.get("box_width_in", "1.75")))
        self.height_var.set(str(settings.get("box_height_in", "0.75")))
        self.keep_aspect_var.set(bool(settings.get("keep_aspect", True)))

    def _save_settings(self) -> None:
        settings = {
            "pdf_path": self.pdf_path_var.get().strip(),
            "logo_dir": self.logo_dir_var.get().strip(),
            "output_dir": self.output_dir_var.get().strip(),
            "anchor": self.anchor_var.get().strip(),
            "x_offset_in": self.x_offset_var.get().strip(),
            "y_offset_in": self.y_offset_var.get().strip(),
            "box_width_in": self.width_var.get().strip(),
            "box_height_in": self.height_var.get().strip(),
            "keep_aspect": self.keep_aspect_var.get(),
        }
        SETTINGS_PATH.write_text(json.dumps(settings, indent=2), encoding="utf-8")

    def _on_close(self) -> None:
        self._save_settings()
        self.root.destroy()


def main() -> None:
    root = tk.Tk()
    HeaderBatchApp(root)
    root.mainloop()


if __name__ == "__main__":
    main()
