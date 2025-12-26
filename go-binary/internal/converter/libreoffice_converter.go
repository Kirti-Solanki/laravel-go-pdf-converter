package converter

import (
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"strings"

	"github.com/nikunjkothiya/gopdfconv/pkg/errors"
)

// LibreOfficeConverter handles conversion using LibreOffice
type LibreOfficeConverter struct {
	libreOfficePath string
}

// NewLibreOfficeConverter creates a new LibreOffice converter
func NewLibreOfficeConverter(path string) *LibreOfficeConverter {
	return &LibreOfficeConverter{
		libreOfficePath: path,
	}
}

// pathToFileURL converts a file path to a file:// URL (handles Windows paths)
func pathToFileURL(path string) string {
	// Convert backslashes to forward slashes
	path = strings.ReplaceAll(path, "\\", "/")
	
	if runtime.GOOS == "windows" {
		// Windows: file:///C:/path/to/file
		if len(path) >= 2 && path[1] == ':' {
			return "file:///" + path
		}
	}
	// Unix: file:///path/to/file
	return "file://" + path
}

// Convert performs the conversion using LibreOffice
func (c *LibreOfficeConverter) Convert(inputPath, outputPath string) error {
	// Check if file exists
	if _, err := os.Stat(inputPath); os.IsNotExist(err) {
		return errors.NewWithFile(errors.ErrFileNotFound, "File not found", inputPath)
	}

	// Create output directory if it doesn't exist
	outputDir := filepath.Dir(outputPath)
	if err := os.MkdirAll(outputDir, 0755); err != nil {
		return errors.Wrap(err, errors.ErrWriteFailed, "Failed to create output directory")
	}

	// Create a temp directory for LibreOffice output and profile
	tempDir, err := os.MkdirTemp("", "gopdfconv-*")
	if err != nil {
		return errors.Wrap(err, errors.ErrConversionFailed, "Failed to create temp directory")
	}
	defer os.RemoveAll(tempDir)

	// Create profile directory
	profileDir := filepath.Join(tempDir, "profile")
	os.MkdirAll(profileDir, 0755)

	// Convert input path to absolute path
	absInputPath, err := filepath.Abs(inputPath)
	if err != nil {
		absInputPath = inputPath
	}

	// Build user installation URL for temp profile
	userInstallURL := pathToFileURL(profileDir)

	// Detect file type for proper filter
	ext := strings.ToLower(filepath.Ext(inputPath))
	convertFilter := "pdf"
	if ext == ".pptx" || ext == ".ppt" || ext == ".odp" {
		convertFilter = "pdf:impress_pdf_Export"
	} else if ext == ".xlsx" || ext == ".xls" || ext == ".ods" {
		convertFilter = "pdf:calc_pdf_Export"
	} else if ext == ".docx" || ext == ".doc" || ext == ".odt" {
		convertFilter = "pdf:writer_pdf_Export"
	}

	// Run LibreOffice conversion with a fresh temporary user profile
	cmd := exec.Command(c.libreOfficePath,
		"-env:UserInstallation="+userInstallURL,
		"--headless",
		"--invisible",
		"--nologo",
		"--nofirststartwizard",
		"--convert-to", convertFilter,
		"--outdir", tempDir,
		absInputPath,
	)

	// Set environment to avoid GUI issues
	cmd.Env = append(os.Environ(), "HOME="+tempDir)

	output, err := cmd.CombinedOutput()
	if err != nil {
		return errors.NewWithDetails(errors.ErrConversionFailed, "LibreOffice conversion failed", inputPath, string(output))
	}

	// Find the generated PDF file in temp directory
	var generatedPDF string
	files, err := os.ReadDir(tempDir)
	if err != nil {
		return errors.New(errors.ErrConversionFailed, "Failed to read temp directory")
	}

	for _, f := range files {
		if !f.IsDir() && strings.HasSuffix(strings.ToLower(f.Name()), ".pdf") {
			generatedPDF = filepath.Join(tempDir, f.Name())
			break
		}
	}

	if generatedPDF == "" {
		return errors.NewWithDetails(errors.ErrConversionFailed, "LibreOffice failed to generate PDF", inputPath, string(output))
	}

	// Move the generated PDF to the final output path
	if err := os.Rename(generatedPDF, outputPath); err != nil {
		// If rename fails (e.g. across filesystems), try copy
		if err := copyFile(generatedPDF, outputPath); err != nil {
			return errors.Wrap(err, errors.ErrWriteFailed, "Failed to move generated PDF")
		}
	}

	return nil
}

// ConvertTo converts a file to a specific format using LibreOffice
func (c *LibreOfficeConverter) ConvertTo(inputPath, outputPath, format string) error {
	tempDir, err := os.MkdirTemp("", "gopdfconv-lo-*")
	if err != nil {
		return errors.Wrap(err, errors.ErrConversionFailed, "Failed to create temp directory")
	}
	defer os.RemoveAll(tempDir)

	// Create profile directory
	profileDir := filepath.Join(tempDir, "profile")
	os.MkdirAll(profileDir, 0755)

	// Convert input path to absolute path
	absInputPath, err := filepath.Abs(inputPath)
	if err != nil {
		absInputPath = inputPath
	}

	// Build user installation URL for temp profile
	userInstallURL := pathToFileURL(profileDir)

	cmd := exec.Command(c.libreOfficePath,
		"-env:UserInstallation="+userInstallURL,
		"--headless",
		"--invisible",
		"--nologo",
		"--nofirststartwizard",
		"--convert-to", format,
		"--outdir", tempDir,
		absInputPath,
	)

	// Set environment to avoid GUI issues
	cmd.Env = append(os.Environ(), "HOME="+tempDir)

	output, err := cmd.CombinedOutput()
	if err != nil {
		return errors.NewWithDetails(errors.ErrConversionFailed, "LibreOffice conversion failed", inputPath, string(output))
	}

	// Find the generated file in temp directory
	files, err := os.ReadDir(tempDir)
	if err != nil {
		return errors.Wrap(err, errors.ErrConversionFailed, "Failed to read temp directory")
	}

	var generatedFile string
	for _, f := range files {
		if !f.IsDir() && f.Name() != "profile" && !strings.HasPrefix(f.Name(), ".") {
			// Check if it's the converted file (not the profile directory)
			fPath := filepath.Join(tempDir, f.Name())
			if info, err := os.Stat(fPath); err == nil && !info.IsDir() {
				generatedFile = fPath
				break
			}
		}
	}

	if generatedFile == "" {
		return errors.NewWithDetails(errors.ErrConversionFailed, "LibreOffice failed to generate output file", inputPath, string(output))
	}

	// Move to final destination
	if err := os.Rename(generatedFile, outputPath); err != nil {
		// If rename fails (e.g. cross-device), try copy
		input, err := os.ReadFile(generatedFile)
		if err != nil {
			return err
		}
		return os.WriteFile(outputPath, input, 0644)
	}

	return nil
}

// copyFile is a helper to copy a file if rename fails
func copyFile(src, dst string) error {
	data, err := os.ReadFile(src)
	if err != nil {
		return err
	}
	return os.WriteFile(dst, data, 0644)
}
