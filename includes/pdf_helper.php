<?php
/**
 * Simple self-contained PDF generator for RJPES
 * Constructs raw PDF 1.4 binary data and sends it as a download stream.
 */
class RJPES_PDF {
    private $buffer = '';
    private $offsets = [];
    private $objects = [];
    private $page_number = 0;
    private $page_objects = [];
    
    // Page layout settings
    private $w = 595.28; // A4 Width in points (72 points/inch)
    private $h = 841.89; // A4 Height in points
    private $margin_left = 54; // 0.75 inch margin
    private $margin_right = 54;
    private $margin_top = 54;
    private $margin_bottom = 54;
    
    // Current text cursor
    private $y = 780; // Starts near top
    
    public function __construct() {
        $this->buffer = "%PDF-1.4\n";
    }
    
    private function write($data) {
        $this->buffer .= $data;
    }
    
    private function new_object($id = null) {
        if ($id === null) {
            $id = count($this->offsets) + 1;
            while (isset($this->offsets[$id])) {
                $id++;
            }
        }
        $this->offsets[$id] = strlen($this->buffer);
        $this->write($id . " 0 obj\n");
        return $id;
    }
    
    private function end_object() {
        $this->write("endobj\n");
    }
    
    private function escape_text($text) {
        // Remove unsupported characters and escape parentheses
        $text = str_replace(array('\\', '(', ')'), array('\\\\', '\\(', '\\)'), $text);
        return $text;
    }
    
    /**
     * Splits text into lines fitting the page width
     */
    private function split_text_to_lines($text, $font_size, $max_width) {
        // Average char width factor for Helvetica is roughly 0.5 * font_size
        $char_width = $font_size * 0.52;
        $max_chars = floor($max_width / $char_width);
        
        $lines = [];
        $paragraphs = explode("\n", $text);
        
        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if (empty($paragraph)) {
                $lines[] = '';
                continue;
            }
            
            $words = explode(' ', $paragraph);
            $current_line = '';
            
            foreach ($words as $word) {
                $test_line = empty($current_line) ? $word : $current_line . ' ' . $word;
                if (strlen($test_line) > $max_chars) {
                    if (!empty($current_line)) {
                        $lines[] = $current_line;
                        $current_line = $word;
                    } else {
                        // Word itself is wider than line, force wrap it
                        $lines[] = $word;
                        $current_line = '';
                    }
                } else {
                    $current_line = $test_line;
                }
            }
            if (!empty($current_line)) {
                $lines[] = $current_line;
            }
        }
        return $lines;
    }

    /**
     * Cleans HTML content for display as plain text in PDF
     */
    private function clean_html_for_pdf($html) {
        if (empty($html)) {
            return '';
        }
        
        // Replace <br> and <br /> with newlines
        $text = preg_replace('/<br\s*\/?>/i', "\n", $html);
        
        // Replace closed block tags with newlines
        $block_tags = ['p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'li'];
        foreach ($block_tags as $tag) {
            $text = preg_replace('/<\/' . $tag . '>/i', "\n", $text);
        }
        
        // Convert list item open tags to bullet format
        $text = preg_replace('/<li>/i', '- ', $text);
        
        // Strip remaining HTML tags
        $text = strip_tags($text);
        
        // Decode HTML entities (e.g. &nbsp;, &amp;)
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        // Standardize carriage returns
        $text = str_replace("\r", "", $text);
        
        // Clean up excessive newlines while retaining paragraph separations
        $text = preg_replace("/\n\n+/", "\n\n", $text);
        
        return trim($text);
    }

    /**
     * Estimates text width in Helvetica/Helvetica-Bold
     */
    private function get_text_width($text, $font_size) {
        $width = 0;
        $len = strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $char = $text[$i];
            if ($char >= '0' && $char <= '9') {
                $width += 0.556 * $font_size;
            } elseif ($char === '.' || $char === ',' || $char === ' ') {
                $width += 0.278 * $font_size;
            } elseif ($char === '%') {
                $width += 0.833 * $font_size;
            } elseif ($char === '(' || $char === ')') {
                $width += 0.333 * $font_size;
            } elseif ($char === 'I' || $char === 'i' || $char === 'l') {
                $width += 0.222 * $font_size;
            } elseif ($char >= 'A' && $char <= 'Z') {
                $width += 0.667 * $font_size;
            } else {
                $width += 0.556 * $font_size;
            }
        }
        return $width;
    }

    /**
     * Embeds a JPEG signature image into the PDF.
     * Returns an array with metadata if successful, or null on failure.
     */
    private function embed_jpeg_image($image_path) {
        if (!file_exists($image_path)) {
            return null;
        }
        $info = @getimagesize($image_path);
        if (!$info || $info[2] !== IMAGETYPE_JPEG) {
            return null;
        }
        $width = $info[0];
        $height = $info[1];
        
        $data = @file_get_contents($image_path);
        if ($data === false) {
            return null;
        }
        
        $img_id = $this->new_object();
        $this->write("<<\n");
        $this->write("  /Type /XObject\n");
        $this->write("  /Subtype /Image\n");
        $this->write("  /Width " . $width . "\n");
        $this->write("  /Height " . $height . "\n");
        $this->write("  /ColorSpace /DeviceRGB\n");
        $this->write("  /BitsPerComponent 8\n");
        $this->write("  /Filter /DCTDecode\n");
        $this->write("  /Length " . strlen($data) . "\n");
        $this->write(">>\n");
        $this->write("stream\n" . $data . "\nendstream\n");
        $this->end_object();
        
        return [
            'id' => $img_id,
            'w' => $width,
            'h' => $height
        ];
    }
    
    /**
     * Build the PDF document contents
     */
    public function generate($journal) {
        $title = $journal['title'];
        $author = $journal['author_name'];
        $domain = $journal['subject_domain'];
        $number = $journal['journal_number'];
        $abstract = $journal['abstract'];
        $content = $this->clean_html_for_pdf($journal['content'] ?? '');
        $volume = $journal['volume'] ?? '20';
        $issue = $journal['issue'] ?? '1';
        $pub_at = !empty($journal['published_at']) ? strtotime($journal['published_at']) : time();
        $date = date('d F Y', $pub_at);
        $month_year = date('F Y', $pub_at);
        
        $photo_to_embed = null;
        if (!empty($journal['author_photo'])) {
            $photo_abs_path = __DIR__ . '/../' . ltrim(str_replace(['/', '\\'], '/', $journal['author_photo']), '/');
            if (file_exists($photo_abs_path)) {
                $photo_info_size = @getimagesize($photo_abs_path);
                if ($photo_info_size && $photo_info_size[2] === IMAGETYPE_JPEG) {
                    $photo_to_embed = $photo_abs_path;
                }
            }
        }
        
        // Define page contents
        $content_stream = "";
        
        // Helvetica Bold descriptor
        $font_bold_id = 5;
        // Helvetica Standard descriptor
        $font_normal_id = 6;
        
        // Draw Header Border / RJPES header info
        $content_stream .= "0.5 w\n";
        $content_stream .= "54 785 m 541 785 l S\n"; // top line
        $content_stream .= "54 725 m 541 725 l S\n"; // bottom line
        
        // Header Text
        $content_stream .= "BT\n";
        $content_stream .= "/F2 10 Tf\n"; // Bold Font
        $content_stream .= "54 770 Td (" . $this->escape_text("RESEARCH JOURNAL ON PHYSICAL EDUCATION AND SPORTS (RJPES)") . ") Tj\n";
        $content_stream .= "ET\n";
        
        $content_stream .= "BT\n";
        $content_stream .= "/F1 9 Tf\n"; // Regular Font
        $content_stream .= "54 754 Td (" . $this->escape_text("ISSN: 0975-4687 | Volume " . $volume . ", Issue " . $issue . " (" . $month_year . ") | UGC Guidelines Followed") . ") Tj\n";
        $content_stream .= "ET\n";
        
        $content_stream .= "BT\n";
        $content_stream .= "/F2 9 Tf\n";
        $content_stream .= "0 g\n"; // Black text
        $content_stream .= "54 740 Td (" . $this->escape_text("Journal No: " . $number) . ") Tj\n"; // Left aligned
        $content_stream .= "ET\n";
        
        // Title (wrap if needed)
        $max_width = $this->w - $this->margin_left - $this->margin_right;
        $title_lines = $this->split_text_to_lines($title, 16, $max_width);
        
        $current_y = 690;
        foreach ($title_lines as $t_line) {
            $content_stream .= "BT\n";
            $content_stream .= "/F2 16 Tf\n"; // Bold Title
            $content_stream .= "54 " . $current_y . " Td (" . $this->escape_text($t_line) . ") Tj\n";
            $content_stream .= "ET\n";
            $current_y -= 22;
        }
        
        // Authors & Domain
        $current_y -= 10;
        $content_stream .= "BT\n";
        $content_stream .= "/F2 11 Tf\n";
        $content_stream .= "54 " . $current_y . " Td (" . $this->escape_text("Author: " . $author) . ") Tj\n";
        $content_stream .= "ET\n";
        
        $current_y -= 15;
        $content_stream .= "BT\n";
        $content_stream .= "/F1 10 Tf\n";
        $content_stream .= "54 " . $current_y . " Td (" . $this->escape_text("Subject Domain: " . $domain . " | Published on: " . $date) . ") Tj\n";
        $content_stream .= "ET\n";
        
        if ($photo_to_embed) {
            $p_w = 70;
            $p_h = 90;
            $p_x = 541 - $p_w; // 471
            $p_y = $current_y - 55; // bottom of photo
            
            // Draw photo
            $content_stream .= "q\n";
            $content_stream .= sprintf("%.2f 0 0 %.2f %.2f %.2f cm\n", $p_w, $p_h, $p_x, $p_y);
            $content_stream .= "/AuthorPhoto Do\n";
            $content_stream .= "Q\n";
            
            // Draw border outline around photo
            $content_stream .= "0.5 w 0.8 G\n"; // 0.8 grey
            $content_stream .= sprintf("%.2f %.2f %.2f %.2f re S\n", $p_x - 1, $p_y - 1, $p_w + 2, $p_h + 2);
            $content_stream .= "0 G\n"; // Reset stroke to black
            
            // Update current_y to be below the photo
            $current_y = $p_y;
        }
        
        $current_y -= 25;
        $content_stream .= "0.5 w 0 g\n"; // Black stroke, black fill
        $content_stream .= "54 " . ($current_y - 10) . " m 541 " . ($current_y - 10) . " l S\n";
        
        $current_y -= 25;
        $content_stream .= "BT\n";
        $content_stream .= "0 g\n"; // Reset to black text
        $content_stream .= "/F2 12 Tf\n";
        $content_stream .= "54 " . $current_y . " Td (Abstract) Tj\n";
        $content_stream .= "ET\n";
        
        $current_y -= 18;
        $abstract_lines = $this->split_text_to_lines($abstract, 10, $max_width);
        foreach ($abstract_lines as $ab_line) {
            if ($current_y < $this->margin_bottom) {
                // If abstract is too long, we keep rendering on new page but for most abstracts it fits.
                // Simple generator targets single page for abstract and moves content down.
            }
            $content_stream .= "BT\n";
            $content_stream .= "0 g\n"; // Black text
            $content_stream .= "/F1 10 Tf\n";
            $content_stream .= "54 " . $current_y . " Td (" . $this->escape_text($ab_line) . ") Tj\n";
            $content_stream .= "ET\n";
            $current_y -= 14;
        }
        
        $content_stream .= "0.5 w\n";
        $content_stream .= "54 " . ($current_y - 5) . " m 541 " . ($current_y - 5) . " l S\n";

        // Add signature and editor name to the bottom of the first page
        $editor_name = rjpes_get_setting('editor_name', 'Prof. (Dr.) Biju Lona K.');
        $sig_path = rjpes_get_setting('editor_signature', '');
        $has_sig = !empty($sig_path);
        
        $content_stream .= "0.5 w\n";
        $content_stream .= "54 130 m 541 130 l S\n"; // Divider line above signature area
        
        if ($has_sig) {
            $sig_abs_path = __DIR__ . '/../' . ltrim(str_replace(['/', '\\'], '/', $sig_path), '/');
            $img_w = 150;
            $img_h = 50;
            if (file_exists($sig_abs_path)) {
                $info = @getimagesize($sig_abs_path);
                if ($info) {
                    $img_w = $info[0];
                    $img_h = $info[1];
                }
            }
            $max_sig_h = 30;
            $max_sig_w = 120;
            $scale = min($max_sig_w / $img_w, $max_sig_h / $img_h);
            $draw_w = $img_w * $scale;
            $draw_h = $img_h * $scale;
            
            $sig_y = 90;
            $content_stream .= "q\n";
            $content_stream .= sprintf("%.2f 0 0 %.2f 380 %.2f cm\n", $draw_w, $draw_h, $sig_y);
            $content_stream .= "/SigImg Do\n";
            $content_stream .= "Q\n";
        }
        
        $content_stream .= "BT\n";
        $content_stream .= "0.1 g\n";
        $content_stream .= "/F2 9.5 Tf\n";
        $content_stream .= "380 75 Td (" . $this->escape_text($editor_name) . ") Tj\n";
        $content_stream .= "ET\n";
        
        $content_stream .= "BT\n";
        $content_stream .= "0.4 g\n";
        $content_stream .= "/F1 8.5 Tf\n";
        $content_stream .= "380 62 Td (Editor-in-Chief, RJPES) Tj\n";
        $content_stream .= "ET\n";
        
        // Add footer to first page
        $content_stream .= "BT\n";
        $content_stream .= "/F1 8 Tf\n";
        $content_stream .= "54 40 Td (" . $this->escape_text("RJPES Journal Portal | Official Publication of ACTPE, Calicut University") . ") Tj\n";
        $content_stream .= "ET\n";
        
        $content_stream .= "BT\n";
        $content_stream .= "/F1 8 Tf\n";
        $content_stream .= "500 40 Td (" . $this->escape_text("Page 1") . ") Tj\n";
        $content_stream .= "ET\n";
        
        // Full Text Content
        if (!empty($content)) {
            // First page is complete with the cover page details and signature
            $pages_content = [$content_stream];
            
            // Start the second page
            $current_page_str = "0.5 w\n";
            $current_page_str .= "54 785 m 541 785 l S\n"; // Header line
            $current_page_str .= "BT\n";
            $current_page_str .= "/F2 9 Tf\n";
            $current_page_str .= "54 792 Td (" . $this->escape_text("RJPES | Vol. " . $volume . ", Issue " . $issue . " (" . $month_year . ") | Journal No: " . $number) . ") Tj\n";
            $current_page_str .= "ET\n";
            
            $current_y = 750;
            $content_stream_heading = "BT\n";
            $content_stream_heading .= "0 g\n"; // Black text
            $content_stream_heading .= "/F2 12 Tf\n";
            $content_stream_heading .= "54 " . $current_y . " Td (Full Article Text) Tj\n";
            $content_stream_heading .= "ET\n";
            
            $current_page_str .= $content_stream_heading;
            
            $current_y -= 20;
            $content_lines = $this->split_text_to_lines($content, 10, $max_width);
            
            $page_num = 2;
            foreach ($content_lines as $c_line) {
                if ($current_y < 70) { // Near page bottom
                    // Add footer to current page
                    $current_page_str .= "BT\n";
                    $current_page_str .= "/F1 8 Tf\n";
                    $current_page_str .= "54 40 Td (" . $this->escape_text("RJPES Journal Portal | Official Publication of ACTPE, Calicut University") . ") Tj\n";
                    $current_page_str .= "ET\n";
                    
                    // Page number
                    $current_page_str .= "BT\n";
                    $current_page_str .= "/F1 8 Tf\n";
                    $current_page_str .= "500 40 Td (" . $this->escape_text("Page " . $page_num) . ") Tj\n";
                    $current_page_str .= "ET\n";
                    
                    $pages_content[] = $current_page_str;
                    $page_num++;
                    
                    // Reset page settings
                    $current_page_str = "0.5 w\n";
                    $current_page_str .= "54 785 m 541 785 l S\n"; // Header line
                    $current_page_str .= "BT\n";
                    $current_page_str .= "/F2 9 Tf\n";
                    $current_page_str .= "54 792 Td (" . $this->escape_text("RJPES | Vol. " . $volume . ", Issue " . $issue . " (" . $month_year . ") | Journal No: " . $number) . ") Tj\n";
                    $current_page_str .= "ET\n";
                    
                    $current_y = 750;
                }
                
                if ($c_line === '') {
                    $current_y -= 8; // small gap for paragraph
                    continue;
                }
                
                $current_page_str .= "BT\n";
                $current_page_str .= "/F1 10 Tf\n";
                $current_page_str .= "54 " . $current_y . " Td (" . $this->escape_text($c_line) . ") Tj\n";
                $current_page_str .= "ET\n";
                $current_y -= 14;
            }
            
            // Add footer to final page
            $current_page_str .= "BT\n";
            $current_page_str .= "/F1 8 Tf\n";
            $current_page_str .= "54 40 Td (" . $this->escape_text("RJPES Journal Portal | Official Publication of ACTPE, Calicut University") . ") Tj\n";
            $current_page_str .= "ET\n";
            
            // Page number
            $current_page_str .= "BT\n";
            $current_page_str .= "/F1 8 Tf\n";
            $current_page_str .= "500 40 Td (" . $this->escape_text("Page " . $page_num) . ") Tj\n";
            $current_page_str .= "ET\n";
            
            $pages_content[] = $current_page_str;
        } else {
            // Only abstract page
            $pages_content = [$content_stream];
        }
        
        // Compile binary objects for PDF
        $catalog_id = $this->new_object();
        $this->write("<< /Type /Catalog /Pages 2 0 R >>\n");
        $this->end_object();
        
        // Reserve index 2 for the Pages object
        $this->offsets[2] = -1;
        
        // Try to embed signature image if configured
        $img_info = null;
        $sig_path = rjpes_get_setting('editor_signature', '');
        if (!empty($sig_path)) {
            $sig_abs_path = __DIR__ . '/../' . ltrim(str_replace(['/', '\\'], '/', $sig_path), '/');
            if (file_exists($sig_abs_path)) {
                $img_info = $this->embed_jpeg_image($sig_abs_path);
            }
        }
        
        // Try to embed author photo if configured
        $author_photo_info = null;
        if (isset($photo_to_embed) && $photo_to_embed) {
            $author_photo_info = $this->embed_jpeg_image($photo_to_embed);
        }
        
        // Font 1 (Regular)
        $font1_id = $this->new_object();
        $this->write("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>\n");
        $this->end_object();
        
        // Font 2 (Bold)
        $font2_id = $this->new_object();
        $this->write("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>\n");
        $this->end_object();

        // Resources object containing fonts
        $resources_id = $this->new_object();
        $xobjects = [];
        if ($img_info) {
            $xobjects[] = "/SigImg " . $img_info['id'] . " 0 R";
        }
        if ($author_photo_info) {
            $xobjects[] = "/AuthorPhoto " . $author_photo_info['id'] . " 0 R";
        }
        
        if (!empty($xobjects)) {
            $this->write("<< /Font << /F1 " . $font1_id . " 0 R /F2 " . $font2_id . " 0 R >> /XObject << " . implode(" ", $xobjects) . " >> >>\n");
        } else {
            $this->write("<< /Font << /F1 " . $font1_id . " 0 R /F2 " . $font2_id . " 0 R >> >>\n");
        }
        $this->end_object();
        
        // Compile pages list
        $page_ids = [];
        $page_obj_ids = [];
        
        foreach ($pages_content as $idx => $p_content) {
            $stream_id = $this->new_object();
            $this->write("<< /Length " . strlen($p_content) . " >>\n");
            $this->write("stream\n" . $p_content . "\nendstream\n");
            $this->end_object();
            $page_ids[] = $stream_id;
            
            $page_obj_id = $this->new_object();
            $this->write("<< /Type /Page /Parent 2 0 R /Resources " . $resources_id . " 0 R /MediaBox [0 0 595.28 841.89] /Contents " . $stream_id . " 0 R >>\n");
            $this->end_object();
            $page_obj_ids[] = $page_obj_id;
        }
        
        // Pages List Catalog
        // Object ID 2 represents /Pages
        $this->offsets[2] = strlen($this->buffer);
        $this->write("2 0 obj\n");
        $kids_str = "[" . implode(" 0 R ", $page_obj_ids) . " 0 R]";
        $this->write("<< /Type /Pages /Kids " . $kids_str . " /Count " . count($page_obj_ids) . " >>\n");
        $this->end_object();
        
        // Cross reference table
        $xref_start = strlen($this->buffer);
        $this->write("xref\n");
        $this->write("0 " . (count($this->offsets) + 1) . "\n");
        $this->write("0000000000 65535 f \n");
        
        for ($i = 1; $i <= count($this->offsets); $i++) {
            $this->write(sprintf("%010d 00000 n \n", $this->offsets[$i]));
        }
        
        $this->write("trailer\n");
        $this->write("<< /Size " . (count($this->offsets) + 1) . " /Root 1 0 R >>\n");
        $this->write("startxref\n");
        $this->write($xref_start . "\n");
        $this->write("%%EOF\n");
        
        return $this->buffer;
    }

    /**
     * Generate an Acceptance Letter PDF for Peer Review
     */
    public function generateAcceptanceLetter($journal, $assigned_at = null) {
        $title = $journal['title'];
        $author = $journal['author_name'];
        $email = $journal['author_email'] ?? '';
        $domain = $journal['subject_domain'];
        $number = $journal['journal_number'];
        $created_at = $journal['created_at'];
        
        $date_assigned = !empty($assigned_at) ? strtotime($assigned_at) : time();
        $date_str = date('d F Y', $date_assigned);
        
        // Define page contents
        $content_stream = "";
        
        // Helvetica Bold descriptor
        $font_bold_id = 5;
        // Helvetica Standard descriptor
        $font_normal_id = 6;
        
        // Deep Navy color definitions for PDF
        $navy_fill = "0.04 0.13 0.25 rg\n";
        $navy_stroke = "0.04 0.13 0.25 RG\n";
        $black_fill = "0 g\n";
        $black_stroke = "0 G\n";
        
        // Draw Header Border / RJPES header info
        $content_stream .= "0.75 w\n";
        $content_stream .= $navy_stroke;
        $content_stream .= "54 722 m 541 722 l S\n"; // top thick line
        $content_stream .= "0.25 w\n";
        $content_stream .= "54 718 m 541 718 l S\n"; // bottom thin line
        
        // Header Text
        $content_stream .= "BT\n";
        $content_stream .= $navy_fill;
        $content_stream .= "/F2 12 Tf\n"; // Bold Font
        $content_stream .= "54 772 Td (" . $this->escape_text("RESEARCH JOURNAL ON PHYSICAL EDUCATION AND SPORTS (RJPES)") . ") Tj\n";
        $content_stream .= "ET\n";
        
        $content_stream .= "BT\n";
        $content_stream .= "0.3 g\n"; // Dark Gray text
        $content_stream .= "/F1 9 Tf\n"; // Regular Font
        $content_stream .= "54 756 Td (" . $this->escape_text("ISSN: 0975-4687 (Online) | UGC Guidelines Followed | Ref. No: RJPES/AL") . ") Tj\n";
        $content_stream .= "ET\n";
        
        $content_stream .= "BT\n";
        $content_stream .= "0.3 g\n";
        $content_stream .= "/F1 9 Tf\n";
        $content_stream .= "54 742 Td (" . $this->escape_text("Official Publication of ACTPE, University of Calicut, Kerala, India") . ") Tj\n";
        $content_stream .= "ET\n";
        
        $content_stream .= "BT\n";
        $content_stream .= "/F2 9 Tf\n";
        $content_stream .= $navy_fill;
        $content_stream .= "54 728 Td (" . $this->escape_text("Email: editor@rjpes.in | Website: www.rjpes.in") . ") Tj\n";
        $content_stream .= "ET\n";
        
        // Letter Reference Number and Date
        $content_stream .= "BT\n";
        $content_stream .= $black_fill;
        $content_stream .= "/F2 9.5 Tf\n";
        $ref_no = "RJPES/AL/" . str_replace('-', '/', $number);
        $content_stream .= "54 695 Td (" . $this->escape_text("Ref No: " . $ref_no) . ") Tj\n";
        $content_stream .= "ET\n";
        
        $content_stream .= "BT\n";
        $content_stream .= $black_fill;
        $content_stream .= "/F2 9.5 Tf\n";
        $content_stream .= "420 695 Td (" . $this->escape_text("Date: " . $date_str) . ") Tj\n";
        $content_stream .= "ET\n";
        
        // Recipient Address
        $content_stream .= "BT\n";
        $content_stream .= $black_fill;
        $content_stream .= "/F2 10 Tf\n";
        $content_stream .= "54 662 Td (" . $this->escape_text("To,") . ") Tj\n";
        $content_stream .= "ET\n";
        
        $content_stream .= "BT\n";
        $content_stream .= "/F2 10 Tf\n";
        $content_stream .= "54 648 Td (" . $this->escape_text($author) . ") Tj\n";
        $content_stream .= "ET\n";
        
        $content_stream .= "BT\n";
        $content_stream .= "/F1 9.5 Tf\n";
        $content_stream .= "54 634 Td (" . $this->escape_text("Corresponding Author") . ") Tj\n";
        $content_stream .= "ET\n";
        
        if (!empty($email)) {
            $content_stream .= "BT\n";
            $content_stream .= "/F1 9.5 Tf\n";
            $content_stream .= "54 620 Td (" . $this->escape_text("Email: " . $email) . ") Tj\n";
            $content_stream .= "ET\n";
        }
        
        // Subject line
        $content_stream .= "BT\n";
        $content_stream .= $navy_fill;
        $content_stream .= "/F2 10.5 Tf\n";
        $content_stream .= "54 588 Td (" . $this->escape_text("Subject: Letter of Acceptance (Manuscript under Peer Review)") . ") Tj\n";
        $content_stream .= "ET\n";
        
        // Underline the subject
        $content_stream .= "0.5 w\n";
        $content_stream .= $navy_stroke;
        $content_stream .= "54 583 m 385 583 l S\n";
        
        // Salutation
        $content_stream .= "BT\n";
        $content_stream .= $black_fill;
        $content_stream .= "/F1 10 Tf\n";
        $content_stream .= "54 558 Td (" . $this->escape_text("Dear Author,") . ") Tj\n";
        $content_stream .= "ET\n";
        
        // Body paragraph 1
        $content_stream .= "BT\n";
        $content_stream .= "/F1 10 Tf\n";
        $content_stream .= "54 538 Td (" . $this->escape_text("We are pleased to inform you that your manuscript / research article titled:") . ") Tj\n";
        $content_stream .= "ET\n";
        
        // Indented wrapped Title
        $max_width = $this->w - $this->margin_left - $this->margin_right - 30; // indent by 30pt
        $title_lines = $this->split_text_to_lines('"' . $title . '"', 10.5, $max_width);
        
        $current_y = 516;
        foreach ($title_lines as $t_line) {
            $content_stream .= "BT\n";
            $content_stream .= $black_fill;
            $content_stream .= "/F2 10.5 Tf\n"; // Bold Title
            $content_stream .= "74 " . $current_y . " Td (" . $this->escape_text($t_line) . ") Tj\n";
            $content_stream .= "ET\n";
            $current_y -= 15;
        }
        
        $current_y -= 5;
        // Body paragraph 2
        $body_text2 = "submitted to the Research Journal on Physical Education and Sports (RJPES) has been successfully verified by our editorial board and is formally accepted for the peer review process. The manuscript details are outlined below:";
        $body_lines2 = $this->split_text_to_lines($body_text2, 10, $this->w - $this->margin_left - $this->margin_right);
        
        foreach ($body_lines2 as $b_line) {
            $content_stream .= "BT\n";
            $content_stream .= $black_fill;
            $content_stream .= "/F1 10 Tf\n";
            $content_stream .= "54 " . $current_y . " Td (" . $this->escape_text($b_line) . ") Tj\n";
            $content_stream .= "ET\n";
            $current_y -= 14;
        }
        
        // Draw a light grey box for details
        $current_y -= 12;
        $box_height = 76;
        $box_y = $current_y - $box_height;
        $box_width = 487;
        
        // Fill box with light grey background
        $content_stream .= "0.97 g\n"; // light grey fill
        $content_stream .= "54 " . $box_y . " " . $box_width . " " . $box_height . " re f\n";
        
        // Stroke box outline in navy
        $content_stream .= "0.5 w\n";
        $content_stream .= $navy_stroke;
        $content_stream .= "54 " . $box_y . " " . $box_width . " " . $box_height . " re S\n";
        
        // Write details inside the box
        $dy = $current_y - 18;
        $details = [
            ['Manuscript Ref No:', $number],
            ['Subject Domain:', $domain],
            ['Submission Date:', date('d F Y', strtotime($created_at))],
            ['Editorial Status:', 'Under Peer Review (Verifier Assigned)']
        ];
        
        foreach ($details as $item) {
            $content_stream .= "BT\n";
            $content_stream .= $navy_fill;
            $content_stream .= "/F2 9.5 Tf\n";
            $content_stream .= "74 " . $dy . " Td (" . $this->escape_text($item[0]) . ") Tj\n";
            $content_stream .= "ET\n";
            
            $content_stream .= "BT\n";
            $content_stream .= $black_fill;
            $content_stream .= "/F1 9.5 Tf\n";
            $content_stream .= "194 " . $dy . " Td (" . $this->escape_text($item[1]) . ") Tj\n";
            $content_stream .= "ET\n";
            
            $dy -= 14;
        }
        
        $current_y = $box_y - 20;
        
        // Body paragraph 3
        $body_text3 = "Your manuscript has been routed to our expert verifiers for detailed peer evaluation. Review outcomes, recommendation comments, and final publishing requirements will be communicated to you upon completion. We appreciate your selection of RJPES as the venue for sharing your research.";
        $body_lines3 = $this->split_text_to_lines($body_text3, 10, $this->w - $this->margin_left - $this->margin_right);
        
        foreach ($body_lines3 as $b_line) {
            $content_stream .= "BT\n";
            $content_stream .= $black_fill;
            $content_stream .= "/F1 10 Tf\n";
            $content_stream .= "54 " . $current_y . " Td (" . $this->escape_text($b_line) . ") Tj\n";
            $content_stream .= "ET\n";
            $current_y -= 14;
        }
        
        $current_y -= 15;
        // Closing
        $content_stream .= "BT\n";
        $content_stream .= "/F1 10 Tf\n";
        $content_stream .= "54 " . $current_y . " Td (" . $this->escape_text("Yours sincerely,") . ") Tj\n";
        $content_stream .= "ET\n";
        
        // Editor Signature Placeholder / Name
        $current_y -= 45; // Space for signature
        
        $sig_path = rjpes_get_setting('editor_signature', '');
        $has_sig = !empty($sig_path);
        
        if ($has_sig) {
            $sig_abs_path = __DIR__ . '/../' . ltrim(str_replace(['/', '\\'], '/', $sig_path), '/');
            $img_w = 150;
            $img_h = 50; // fallback
            if (file_exists($sig_abs_path)) {
                $info = @getimagesize($sig_abs_path);
                if ($info) {
                    $img_w = $info[0];
                    $img_h = $info[1];
                }
            }
            $max_sig_h = 35;
            $max_sig_w = 150;
            $scale = min($max_sig_w / $img_w, $max_sig_h / $img_h);
            $draw_w = $img_w * $scale;
            $draw_h = $img_h * $scale;
            
            $sig_y = $current_y + 5;
            
            $content_stream .= "q\n";
            $content_stream .= sprintf("%.2f 0 0 %.2f 54 %.2f cm\n", $draw_w, $draw_h, $sig_y);
            $content_stream .= "/SigImg Do\n";
            $content_stream .= "Q\n";
        }
        
        $editor_name = rjpes_get_setting('editor_name', 'Prof. (Dr.) Biju Lona K.');
        
        $content_stream .= "BT\n";
        $content_stream .= $navy_fill;
        $content_stream .= "/F2 10.5 Tf\n";
        $content_stream .= "54 " . $current_y . " Td (" . $this->escape_text($editor_name) . ") Tj\n";
        $content_stream .= "ET\n";
        
        $current_y -= 14;
        $content_stream .= "BT\n";
        $content_stream .= $black_fill;
        $content_stream .= "/F1 9.5 Tf\n";
        $content_stream .= "54 " . $current_y . " Td (" . $this->escape_text("Editor-in-Chief") . ") Tj\n";
        $content_stream .= "ET\n";
        
        $current_y -= 13;
        $content_stream .= "BT\n";
        $content_stream .= "/F1 9 Tf\n";
        $content_stream .= "54 " . $current_y . " Td (" . $this->escape_text("Research Journal on Physical Education and Sports (RJPES)") . ") Tj\n";
        $content_stream .= "ET\n";
        
        $current_y -= 13;
        $content_stream .= "BT\n";
        $content_stream .= "/F1 9 Tf\n";
        $content_stream .= "54 " . $current_y . " Td (" . $this->escape_text("Official Publication of ACTPE, University of Calicut") . ") Tj\n";
        $content_stream .= "ET\n";
        
        // Footer Line & Text
        $content_stream .= "0.5 w\n";
        $content_stream .= "0.7 g\n"; // Light Gray line
        $content_stream .= "54 50 m 541 50 l S\n";
        
        $content_stream .= "BT\n";
        $content_stream .= "0.5 g\n"; // Medium Gray text
        $content_stream .= "/F1 8 Tf\n";
        $footer_text = "RJPES Editorial Office | Association of College Teachers of Physical Education | Calicut University | Website: www.rjpes.in";
        $content_stream .= "80 38 Td (" . $this->escape_text($footer_text) . ") Tj\n";
        $content_stream .= "ET\n";
        
        $pages_content = [$content_stream];
        
        // Compile binary objects for PDF
        $catalog_id = $this->new_object();
        $this->write("<< /Type /Catalog /Pages 2 0 R >>\n");
        $this->end_object();
        
        // Reserve index 2 for the Pages object
        $this->offsets[2] = -1;
        
        // Try to embed signature image if configured
        $img_info = null;
        $sig_path = rjpes_get_setting('editor_signature', '');
        if (!empty($sig_path)) {
            $sig_abs_path = __DIR__ . '/../' . ltrim(str_replace(['/', '\\'], '/', $sig_path), '/');
            if (file_exists($sig_abs_path)) {
                $img_info = $this->embed_jpeg_image($sig_abs_path);
            }
        }
        
        // Font 1 (Regular)
        $font1_id = $this->new_object();
        $this->write("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>\n");
        $this->end_object();
        
        // Font 2 (Bold)
        $font2_id = $this->new_object();
        $this->write("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>\n");
        $this->end_object();

        // Resources object containing fonts
        $resources_id = $this->new_object();
        if ($img_info) {
            $this->write("<< /Font << /F1 " . $font1_id . " 0 R /F2 " . $font2_id . " 0 R >> /XObject << /SigImg " . $img_info['id'] . " 0 R >> >>\n");
        } else {
            $this->write("<< /Font << /F1 " . $font1_id . " 0 R /F2 " . $font2_id . " 0 R >> >>\n");
        }
        $this->end_object();
        
        // Compile pages list
        $page_ids = [];
        $page_obj_ids = [];
        
        foreach ($pages_content as $idx => $p_content) {
            $stream_id = $this->new_object();
            $this->write("<< /Length " . strlen($p_content) . " >>\n");
            $this->write("stream\n" . $p_content . "\nendstream\n");
            $this->end_object();
            $page_ids[] = $stream_id;
            
            $page_obj_id = $this->new_object();
            $this->write("<< /Type /Page /Parent 2 0 R /Resources " . $resources_id . " 0 R /MediaBox [0 0 595.28 841.89] /Contents " . $stream_id . " 0 R >>\n");
            $this->end_object();
            $page_obj_ids[] = $page_obj_id;
        }
        
        // Pages List Catalog
        // Object ID 2 represents /Pages
        $this->offsets[2] = strlen($this->buffer);
        $this->write("2 0 obj\n");
        $kids_str = "[" . implode(" 0 R ", $page_obj_ids) . " 0 R]";
        $this->write("<< /Type /Pages /Kids " . $kids_str . " /Count " . count($page_obj_ids) . " >>\n");
        $this->end_object();
        
        // Cross reference table
        $xref_start = strlen($this->buffer);
        $this->write("xref\n");
        $this->write("0 " . (count($this->offsets) + 1) . "\n");
        $this->write("0000000000 65535 f \n");
        
        for ($i = 1; $i <= count($this->offsets); $i++) {
            $this->write(sprintf("%010d 00000 n \n", $this->offsets[$i]));
        }
        
        $this->write("trailer\n");
        $this->write("<< /Size " . (count($this->offsets) + 1) . " /Root 1 0 R >>\n");
        $this->write("startxref\n");
        $this->write($xref_start . "\n");
        $this->write("%%EOF\n");
        
        return $this->buffer;
    }

    /**
     * Converts a number to Indian Rupees words format
     */
    private function convert_number_to_words($number) {
        $decimal = round($number - ($no = floor($number)), 2) * 100;
        $hundred = null;
        $digits_length = strlen($no);
        $i = 0;
        $str = array();
        $words = array(
            0 => '', 1 => 'One', 2 => 'Two',
            3 => 'Three', 4 => 'Four', 5 => 'Five', 6 => 'Six',
            7 => 'Seven', 8 => 'Eight', 9 => 'Nine',
            10 => 'Ten', 11 => 'Eleven', 12 => 'Twelve',
            13 => 'Thirteen', 14 => 'Fourteen', 15 => 'Fifteen',
            16 => 'Sixteen', 17 => 'Seventeen', 18 => 'Eighteen',
            19 => 'Nineteen', 20 => 'Twenty', 30 => 'Thirty',
            40 => 'Forty', 50 => 'Fifty', 60 => 'Sixty',
            70 => 'Seventy', 80 => 'Eighty', 90 => 'Ninety'
        );
        $digits = array('', 'Hundred','Thousand','Lakh', 'Crore');
        while( $i < $digits_length ) {
            $divider = ($i == 2) ? 10 : 100;
            $number = floor($no % $divider);
            $no = floor($no / $divider);
            $i += $divider == 10 ? 1 : 2;
            if ($number) {
                $plural = (($counter = count($str)) && $number > 9) ? 's' : null;
                $hundred = ($counter == 1 && $str[0]) ? ' and ' : null;
                $str [] = ($number < 21) ? $words[$number].' '. $digits[$counter]. $plural.' '.$hundred:$words[floor($number / 10) * 10].' '.$words[$number % 10]. ' '.$digits[$counter].$plural.' '.$hundred;
            } else $str[] = null;
        }
        $Rupees = implode('', array_reverse($str));
        $paise = ($decimal > 0) ? "and " . ($words[floor($decimal / 10) * 10] . " " . $words[$decimal % 10]) . ' Paise' : '';
        return trim(($Rupees ? $Rupees . 'Rupees ' : '') . $paise . ' Only');
    }

    /**
     * Generate dynamic GST Tax Invoice PDF
     */
    public function generateGSTInvoice($journal) {
        $title = $journal['title'];
        $author = $journal['author_name'];
        $email = $journal['author_email'] ?? '';
        $domain = $journal['subject_domain'];
        $number = $journal['journal_number'];
        $bill_number = $journal['bill_number'];
        
        $base = floatval($journal['base_amount']);
        $gst = floatval($journal['gst_amount']);
        $total = floatval($journal['payment_amount']);
        $cgst = $gst / 2;
        $sgst = $gst / 2;
        
        $payment_date = !empty($journal['payment_date']) ? strtotime($journal['payment_date']) : time();
        $date_str = date('d F Y', $payment_date);
        
        $transaction_id = $journal['transaction_id'] ?? 'N/A';
        
        // Fetch dynamic GST percentage from DB settings
        $gst_pct = floatval(rjpes_get_setting('gst_percentage', '18'));
        
        // Define page contents
        $content_stream = "";
        
        // Helvetica Bold descriptor
        $font_bold_id = 5;
        // Helvetica Standard descriptor
        $font_normal_id = 6;
        
        // Deep Navy color definitions for PDF
        $navy_fill = "0.04 0.13 0.25 rg\n";
        $navy_stroke = "0.04 0.13 0.25 RG\n";
        $black_fill = "0 g\n";
        $black_stroke = "0 G\n";
        
        // Header Text: SaNDS Lab (Invoice Generator / Payee Company)
        $content_stream .= "BT\n";
        $content_stream .= $navy_fill;
        $content_stream .= "/F2 13 Tf\n";
        $content_stream .= "54 805 Td (" . $this->escape_text("SaNDS Lab") . ") Tj\n";
        $content_stream .= "ET\n";
        
        $content_stream .= "BT\n";
        $content_stream .= "0.3 g\n";
        $content_stream .= "/F1 8.5 Tf\n";
        $content_stream .= "54 793 Td (" . $this->escape_text("XI/866, Chandanam Block, Infopark, Koratty, Thrissur, Kerala - 680308") . ") Tj\n";
        $content_stream .= "ET\n";
        
        $content_stream .= "BT\n";
        $content_stream .= "0.3 g\n";
        $content_stream .= "/F1 8.5 Tf\n";
        $content_stream .= "54 782 Td (" . $this->escape_text("GST No: 32ABQFS7745B1Z1") . ") Tj\n";
        $content_stream .= "ET\n";
        
        $content_stream .= "BT\n";
        $content_stream .= "0.4 g\n";
        $content_stream .= "/F1 8 Tf\n";
        $content_stream .= "54 769 Td (" . $this->escape_text("In association with") . ") Tj\n";
        $content_stream .= "ET\n";
        
        $content_stream .= "BT\n";
        $content_stream .= $navy_fill;
        $content_stream .= "/F2 11 Tf\n";
        $content_stream .= "54 756 Td (" . $this->escape_text("RESEARCH JOURNAL ON PHYSICAL EDUCATION AND SPORTS (RJPES)") . ") Tj\n";
        $content_stream .= "ET\n";
        
        $content_stream .= "BT\n";
        $content_stream .= "0.3 g\n";
        $content_stream .= "/F1 8.5 Tf\n";
        $content_stream .= "54 744 Td (" . $this->escape_text("ISSN: 0975-4687 (Online) | UGC Guidelines Followed | Official Journal Portal") . ") Tj\n";
        $content_stream .= "ET\n";
        
        $content_stream .= "BT\n";
        $content_stream .= "0.3 g\n";
        $content_stream .= "/F1 8.5 Tf\n";
        $content_stream .= "54 732 Td (" . $this->escape_text("ACTPE, Association of College Teachers of Physical Education, Calicut University") . ") Tj\n";
        $content_stream .= "ET\n";
        
        // Right header: "TAX INVOICE"
        $content_stream .= "BT\n";
        $content_stream .= $navy_fill;
        $content_stream .= "/F2 15 Tf\n";
        $content_stream .= "435 790 Td (" . $this->escape_text("TAX INVOICE") . ") Tj\n";
        $content_stream .= "ET\n";
        
        // Draw Header Border Lines
        $content_stream .= "0.75 w\n";
        $content_stream .= $navy_stroke;
        $content_stream .= "54 726 m 541 726 l S\n"; // top thick line
        $content_stream .= "0.25 w\n";
        $content_stream .= "54 722 m 541 722 l S\n"; // bottom thin line
        
        // Invoice Details & Reference Numbers (two columns)
        $y = 696;
        // Left Column (Invoice Details)
        $content_stream .= "BT\n/F2 9.5 Tf\n0 g\n54 " . $y . " Td (Invoice Details) Tj\nET\n";
        $content_stream .= "BT\n/F1 9 Tf\n54 " . ($y - 15) . " Td (Invoice No: " . $this->escape_text($bill_number) . ") Tj\nET\n";
        $content_stream .= "BT\n/F1 9 Tf\n54 " . ($y - 27) . " Td (Invoice Date: " . $this->escape_text($date_str) . ") Tj\nET\n";
        $content_stream .= "BT\n/F1 9 Tf\n54 " . ($y - 39) . " Td (Manuscript Ref: " . $this->escape_text($number) . ") Tj\nET\n";
        
        // Right Column (Payment Details)
        $content_stream .= "BT\n/F2 9.5 Tf\n320 " . $y . " Td (Payment Details) Tj\nET\n";
        $content_stream .= "BT\n/F1 9 Tf\n320 " . ($y - 15) . " Td (Transaction Ref: " . $this->escape_text($transaction_id) . ") Tj\nET\n";
        $content_stream .= "BT\n/F1 9 Tf\n320 " . ($y - 27) . " Td (SAC Code: 998431 (Scholarly Journals)) Tj\nET\n";
        $content_stream .= "BT\n/F1 9 Tf\n320 " . ($y - 39) . " Td (Payment Status: SUCCESS / PAID) Tj\nET\n";
        
        // Bill To section
        $y_bill = $y - 75;
        $content_stream .= "0.5 w\n0.8 g\n54 " . ($y_bill + 18) . " m 541 " . ($y_bill + 18) . " l S\n"; // Divider
        
        $content_stream .= "BT\n/F2 10 Tf\n0.04 0.13 0.25 rg\n54 " . $y_bill . " Td (BILL TO (Author Info)) Tj\nET\n";
        $content_stream .= "BT\n/F2 9.5 Tf\n0 g\n54 " . ($y_bill - 15) . " Td (" . $this->escape_text($author) . ") Tj\nET\n";
        $content_stream .= "BT\n/F1 9 Tf\n54 " . ($y_bill - 27) . " Td (Corresponding Author, RJPES Journal) Tj\nET\n";
        $content_stream .= "BT\n/F1 9 Tf\n54 " . ($y_bill - 39) . " Td (Email: " . $this->escape_text($email) . ") Tj\nET\n";
        $content_stream .= "BT\n/F1 9 Tf\n54 " . ($y_bill - 51) . " Td (Subject Domain: " . $this->escape_text($domain) . ") Tj\nET\n";
        
        // Particulars Table
        $y_table = $y_bill - 83;
        $table_h = 20;
        
        // Header background
        $content_stream .= "0.04 0.13 0.25 rg\n";
        $content_stream .= "54 " . ($y_table - $table_h) . " 487 " . $table_h . " re f\n";
        
        // Header Texts
        $content_stream .= "BT\n/F2 8.5 Tf\n1 g\n";
        $content_stream .= "58 " . ($y_table - 14) . " Td (S.No) Tj\n";
        $content_stream .= "ET\n";
        
        $content_stream .= "BT\n/F2 8.5 Tf\n1 g\n";
        $content_stream .= "88 " . ($y_table - 14) . " Td (Item Description) Tj\n";
        $content_stream .= "ET\n";
        
        $content_stream .= "BT\n/F2 8.5 Tf\n1 g\n";
        $content_stream .= "298 " . ($y_table - 14) . " Td (SAC) Tj\n";
        $content_stream .= "ET\n";
        
        $content_stream .= "BT\n/F2 8.5 Tf\n1 g\n";
        $content_stream .= (410 - $this->get_text_width("Base (INR)", 8.5)) . " " . ($y_table - 14) . " Td (Base (INR)) Tj\n";
        $content_stream .= "ET\n";
        
        $cgst_header = "CGST (" . ($gst_pct/2) . "%)";
        $content_stream .= "BT\n/F2 8.5 Tf\n1 g\n";
        $content_stream .= (470 - $this->get_text_width($cgst_header, 8.5)) . " " . ($y_table - 14) . " Td (" . $cgst_header . ") Tj\n";
        $content_stream .= "ET\n";
        
        $sgst_header = "SGST (" . ($gst_pct/2) . "%)";
        $content_stream .= "BT\n/F2 8.5 Tf\n1 g\n";
        $content_stream .= (537 - $this->get_text_width($sgst_header, 8.5)) . " " . ($y_table - 14) . " Td (" . $sgst_header . ") Tj\n";
        $content_stream .= "ET\n";
        
        // Row Details
        $row_y = $y_table - $table_h;
        $row_h = 35;
        
        $title_short = strlen($title) > 42 ? substr($title, 0, 39) . "..." : $title;
        $desc_line1 = "Publication & Processing Fee";
        $desc_line2 = "Ref: " . $number . " (" . $title_short . ")";
        
        // Row Box & vertical dividers
        $content_stream .= "0.5 w\n0.04 0.13 0.25 RG\n";
        $content_stream .= "54 " . ($row_y - $row_h) . " 487 " . $row_h . " re S\n";
        
        $content_stream .= "84 " . $row_y . " m 84 " . ($row_y - $row_h) . " l S\n";
        $content_stream .= "294 " . $row_y . " m 294 " . ($row_y - $row_h) . " l S\n";
        $content_stream .= "344 " . $row_y . " m 344 " . ($row_y - $row_h) . " l S\n";
        $content_stream .= "414 " . $row_y . " m 414 " . ($row_y - $row_h) . " l S\n";
        $content_stream .= "474 " . $row_y . " m 474 " . ($row_y - $row_h) . " l S\n";
        
        // Row values
        $content_stream .= "BT\n/F1 9 Tf\n0 g\n";
        $content_stream .= "64 " . ($row_y - 16) . " Td (1) Tj\n";
        $content_stream .= "ET\n";
        
        $content_stream .= "BT\n/F1 9 Tf\n0 g\n";
        $content_stream .= "88 " . ($row_y - 14) . " Td (" . $this->escape_text($desc_line1) . ") Tj\n";
        $content_stream .= "ET\n";
        $content_stream .= "BT\n/F1 8.5 Tf\n0.3 g\n";
        $content_stream .= "88 " . ($row_y - 25) . " Td (" . $this->escape_text($desc_line2) . ") Tj\n";
        $content_stream .= "ET\n";
        
        $content_stream .= "BT\n/F1 9 Tf\n0 g\n";
        $content_stream .= "298 " . ($row_y - 16) . " Td (998431) Tj\n";
        $content_stream .= "ET\n";
        
        $base_val_str = number_format($base, 2);
        $content_stream .= "BT\n/F1 9 Tf\n0 g\n";
        $content_stream .= (410 - $this->get_text_width($base_val_str, 9)) . " " . ($row_y - 16) . " Td (" . $base_val_str . ") Tj\n";
        $content_stream .= "ET\n";
        
        $cgst_val_str = number_format($cgst, 2);
        $content_stream .= "BT\n/F1 9 Tf\n0 g\n";
        $content_stream .= (470 - $this->get_text_width($cgst_val_str, 9)) . " " . ($row_y - 16) . " Td (" . $cgst_val_str . ") Tj\n";
        $content_stream .= "ET\n";
        
        $sgst_val_str = number_format($sgst, 2);
        $content_stream .= "BT\n/F1 9 Tf\n0 g\n";
        $content_stream .= (537 - $this->get_text_width($sgst_val_str, 9)) . " " . ($row_y - 16) . " Td (" . $sgst_val_str . ") Tj\n";
        $content_stream .= "ET\n";
        
        // Summary Breakdown Box (right aligned)
        $sum_y = $row_y - $row_h - 12;
        $sum_box_w = 197;
        $sum_box_h = 56;
        $sum_box_x = 344;
        
        $content_stream .= "0.5 w\n0.04 0.13 0.25 RG\n";
        $content_stream .= $sum_box_x . " " . ($sum_y - $sum_box_h) . " " . $sum_box_w . " " . $sum_box_h . " re S\n";
        $content_stream .= $sum_box_x . " " . ($sum_y - 18) . " m 541 " . ($sum_y - 18) . " l S\n";
        $content_stream .= $sum_box_x . " " . ($sum_y - 36) . " m 541 " . ($sum_y - 36) . " l S\n";
        
        // Summary texts
        $subtotal_val = "INR " . number_format($base, 2);
        $content_stream .= "BT\n/F1 8.5 Tf\n0.3 g\n" . ($sum_box_x + 8) . " " . ($sum_y - 12) . " Td (Subtotal (Base Value):) Tj\nET\n";
        $content_stream .= "BT\n/F1 9 Tf\n0 g\n" . (533 - $this->get_text_width($subtotal_val, 9)) . " " . ($sum_y - 12) . " Td (" . $subtotal_val . ") Tj\nET\n";
        
        $gst_val = "INR " . number_format($gst, 2);
        $content_stream .= "BT\n/F1 8.5 Tf\n0.3 g\n" . ($sum_box_x + 8) . " " . ($sum_y - 30) . " Td (Total GST (" . $gst_pct . "%):) Tj\nET\n";
        $content_stream .= "BT\n/F1 9 Tf\n0 g\n" . (533 - $this->get_text_width($gst_val, 9)) . " " . ($sum_y - 30) . " Td (" . $gst_val . ") Tj\nET\n";
        
        $total_val = "INR " . number_format($total, 2);
        $content_stream .= "BT\n/F2 9 Tf\n0.04 0.13 0.25 rg\n" . ($sum_box_x + 8) . " " . ($sum_y - 48) . " Td (Grand Total:) Tj\nET\n";
        $content_stream .= "BT\n/F2 9.5 Tf\n0.04 0.13 0.25 rg\n" . (533 - $this->get_text_width($total_val, 9.5)) . " " . ($sum_y - 48) . " Td (" . $total_val . ") Tj\nET\n";
        
        // Amount in words (left side of summary box)
        $words_text = "Total Value in Words: " . $this->convert_number_to_words($total);
        $words_lines = $this->split_text_to_lines($words_text, 8.5, 270);
        $word_y = $sum_y - 12;
        foreach ($words_lines as $w_line) {
            $content_stream .= "BT\n/F1 8.5 Tf\n0 g\n54 " . $word_y . " Td (" . $this->escape_text($w_line) . ") Tj\nET\n";
            $word_y -= 12;
        }
        
        // Editor signature & name
        $sig_y = $sum_y - $sum_box_h - 70;
        $sig_path = rjpes_get_setting('editor_signature', '');
        $has_sig = !empty($sig_path);
        
        if ($has_sig) {
            $sig_abs_path = __DIR__ . '/../' . ltrim(str_replace(['/', '\\'], '/', $sig_path), '/');
            $img_w = 150;
            $img_h = 50;
            if (file_exists($sig_abs_path)) {
                $info = @getimagesize($sig_abs_path);
                if ($info) {
                    $img_w = $info[0];
                    $img_h = $info[1];
                }
            }
            $max_sig_h = 35;
            $max_sig_w = 150;
            $scale = min($max_sig_w / $img_w, $max_sig_h / $img_h);
            $draw_w = $img_w * $scale;
            $draw_h = $img_h * $scale;
            
            $content_stream .= "q\n";
            $content_stream .= sprintf("%.2f 0 0 %.2f 54 %.2f cm\n", $draw_w, $draw_h, $sig_y + 10);
            $content_stream .= "/SigImg Do\n";
            $content_stream .= "Q\n";
        }
        
        $editor_name = rjpes_get_setting('editor_name', 'Prof. (Dr.) Biju Lona K.');
        
        $content_stream .= "BT\n";
        $content_stream .= "0.04 0.13 0.25 rg\n";
        $content_stream .= "/F2 10 Tf\n";
        $content_stream .= "54 " . $sig_y . " Td (" . $this->escape_text($editor_name) . ") Tj\n";
        $content_stream .= "ET\n";
        
        $content_stream .= "BT\n";
        $content_stream .= "0 g\n";
        $content_stream .= "/F1 9 Tf\n";
        $content_stream .= "54 " . ($sig_y - 12) . " Td (Editor-in-Chief, RJPES) Tj\n";
        $content_stream .= "ET\n";
        
        $content_stream .= "BT\n/F1 8 Tf\n0.4 g\n";
        $content_stream .= "54 " . ($sig_y - 32) . " Td (" . $this->escape_text("Note: This is an official electronic invoice generated for publication and processing fees.") . ") Tj\n";
        $content_stream .= "ET\n";
        
        // Footer Line & Text
        $content_stream .= "0.5 w\n";
        $content_stream .= "0.7 g\n";
        $content_stream .= "54 50 m 541 50 l S\n";
        
        $content_stream .= "BT\n";
        $content_stream .= "0.5 g\n";
        $content_stream .= "/F1 8 Tf\n";
        $footer_text = "RJPES Editorial Office | Association of College Teachers of Physical Education | Calicut University | Website: www.rjpes.in";
        $content_stream .= "80 38 Td (" . $this->escape_text($footer_text) . ") Tj\n";
        $content_stream .= "ET\n";
        
        $pages_content = [$content_stream];
        
        // Compile binary objects for PDF
        $catalog_id = $this->new_object();
        $this->write("<< /Type /Catalog /Pages 2 0 R >>\n");
        $this->end_object();
        
        $this->offsets[2] = -1;
        
        // Embed signature image if configured
        $img_info = null;
        if ($has_sig) {
            $sig_abs_path = __DIR__ . '/../' . ltrim(str_replace(['/', '\\'], '/', $sig_path), '/');
            if (file_exists($sig_abs_path)) {
                $img_info = $this->embed_jpeg_image($sig_abs_path);
            }
        }
        
        // Font 1 (Regular)
        $font1_id = $this->new_object();
        $this->write("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>\n");
        $this->end_object();
        
        // Font 2 (Bold)
        $font2_id = $this->new_object();
        $this->write("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>\n");
        $this->end_object();
        
        // Resources object containing fonts
        $resources_id = $this->new_object();
        if ($img_info) {
            $this->write("<< /Font << /F1 " . $font1_id . " 0 R /F2 " . $font2_id . " 0 R >> /XObject << /SigImg " . $img_info['id'] . " 0 R >> >>\n");
        } else {
            $this->write("<< /Font << /F1 " . $font1_id . " 0 R /F2 " . $font2_id . " 0 R >> >>\n");
        }
        $this->end_object();
        
        // Compile pages list
        $page_ids = [];
        $page_obj_ids = [];
        
        foreach ($pages_content as $idx => $p_content) {
            $stream_id = $this->new_object();
            $this->write("<< /Length " . strlen($p_content) . " >>\n");
            $this->write("stream\n" . $p_content . "\nendstream\n");
            $this->end_object();
            $page_ids[] = $stream_id;
            
            $page_obj_id = $this->new_object();
            $this->write("<< /Type /Page /Parent 2 0 R /Resources " . $resources_id . " 0 R /MediaBox [0 0 595.28 841.89] /Contents " . $stream_id . " 0 R >>\n");
            $this->end_object();
            $page_obj_ids[] = $page_obj_id;
        }
        
        // Pages List Catalog
        $this->offsets[2] = strlen($this->buffer);
        $this->write("2 0 obj\n");
        $kids_str = "[" . implode(" 0 R ", $page_obj_ids) . " 0 R]";
        $this->write("<< /Type /Pages /Kids " . $kids_str . " /Count " . count($page_obj_ids) . " >>\n");
        $this->end_object();
        
        // Cross reference table
        $xref_start = strlen($this->buffer);
        $this->write("xref\n");
        $this->write("0 " . (count($this->offsets) + 1) . "\n");
        $this->write("0000000000 65535 f \n");
        
        for ($i = 1; $i <= count($this->offsets); $i++) {
            $this->write(sprintf("%010d 00000 n \n", $this->offsets[$i]));
        }
        
        $this->write("trailer\n");
        $this->write("<< /Size " . (count($this->offsets) + 1) . " /Root 1 0 R >>\n");
        $this->write("startxref\n");
        $this->write($xref_start . "\n");
        $this->write("%%EOF\n");
        
        return $this->buffer;
    }
}
?>
