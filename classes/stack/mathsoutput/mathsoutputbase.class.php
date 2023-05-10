<?php
// This file is part of Stack - http://stack.maths.ed.ac.uk/
//
// Stack is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Stack is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Stack.  If not, see <http://www.gnu.org/licenses/>.


//fau: #34 change access to class.
require_once(dirname(__FILE__) . '/fact_sheets.class.php');
//fau.

/**
 * The base class for STACK maths output methods.
 *
 * @copyright  2012 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class stack_maths_output {
    /**
     * Do the necessary processing on equations in a language string, before it is output.
     * @param string $string the language string, as loaded by get_string.
     * @return string the string, with equations rendered to HTML.
     */
    public function process_lang_string($string) {
        return $string;
    }

    /**
     * Do the necessary processing on documentation page before the content is
     * passed to Markdown.
     * @param string $docs content of the documentation file.
     * @return string the documentation content ready to pass to Markdown.
     */
    public function pre_process_docs_page($docs) {
        // Double all the \ characters, since Markdown uses it as an escape char,
        // but we use it for maths.
        $docs = str_replace('\\', '\\\\', $docs);

        // Re-double \ characters inside text areas, because we don't want maths
        // renderered there.
        return preg_replace_callback('~(<textarea[^>]*>)(.*?)(</textarea>)~s',
                function ($match) {
                    return $match[1] . str_replace('\\', '\\\\', $match[2]) . $match[3];
                }, $docs);
        $docs = str_replace('\\', '\\\\', $docs);

        return $docs;
    }

    /**
     * Do the necessary processing on documentation page after the content is
     * has been rendered by Markdown.
     * @param string $html rendered version of the documentation page.
     * @return string rendered version of the documentation page with equations inserted.
     */
    public function post_process_docs_page($html) {
        // Now, undo the doubling of the \\ characters inside <code> and <textarea> regions.
        return preg_replace_callback('~(<code>|<textarea[^>]*>)(.*?)(</code>|</textarea>)~s',
                function ($match) {
                    return $match[1] . str_replace('\\\\', '\\', $match[2]) . $match[3];
                }, $html);

        return $html;
    }

    /**
     * Do the necessary processing on content that came from the user, for example
     * the question text or general feedback. The result of calling this method is
     * then passed to Moodle's {@link format_text()} function.
     * @param string $text the content to process.
     * @param qtype_stack_renderer $renderer (options) the STACK renderer, if you have one.
     * @return string the content ready to pass to format_text.
     */
    public function process_display_castext($text, $replacedollars, qtype_stack_renderer $renderer = null) {
        if ($replacedollars) {
            $text = $this->replace_dollars($text);
        }

		//fau: #35 Use ILIAS plotting system instead of Moodle
		global $CFG;
		$text = str_replace('!ploturl!', $CFG->dataurl . '/stack/plots/', $text);
		//fau.

		$text = stack_fact_sheets::display($text, $renderer);

        return $text;
    }

    /**
     * Replace dollar delimiters ($...$ and $$...$$) in text with the safer
     * \(...\) and \[...\].
     * @param string $text the original text.
     * @param bool $markup surround the change with <ins></ins> tags.
     * @return string the text with delimiters replaced.
     */
    public function replace_dollars($text, $markup = false) {
		//fau: #36 Use platform inline delimiters instead of default \(...\)
		$old_text = $text;
		$mathJaxSetting = new ilSetting("MathJax");
		switch ((int)$mathJaxSetting->setting['limiter']) {
			case 0:
				/*\(...\)*/
				$start = '\(';
				$end = '\)';
				break;
			case 1:
				/*[tex]...[/tex]*/
				$start = '[tex]';
				$end = '[/tex]';
				break;
			case 2:
				/*&lt;span class="math"&gt;...&lt;/span&gt;*/
				$start = '&lt;span class="math"&gt;';
				$end = '&lt;/span&gt;';
				break;
			default:
				/*\(...\)*/
				$start = '\(';
				$end = '\)';
				break;
		}

		if ($markup) {
			$displaystart = '<ins>\[</ins>';
			$displayend = '<ins>\]</ins>';
			$inlinestart = '<ins>' . $start . '</ins>';
			$inlineend = '<ins>' . $end . '</ins>';
			$v4start = '<ins>{@</ins>';
			$v4end = '<ins>@}</ins>';
		} else {
			$displaystart = '\[';
			$displayend = '\]';
			$inlinestart = '\(';
			$inlineend = '\)';
			$v4start = '{@';
			$v4end = '@}';
		}
		//fau.
        $text = preg_replace('~(?<!\\\\)\$\$(.*?)(?<!\\\\)\$\$~', $displaystart . '$1' . $displayend, $text);
        $text = preg_replace('~(?<!\\\\)\$(.*?)(?<!\\\\)\$~', $inlinestart . '$1' . $inlineend, $text);

        $temp = stack_utils::all_substring_between($text, '@', '@', true);
        $i = 0;
        foreach ($temp as $cmd) {
            $pos = strpos($text, '@', $i);
            $post = false;
            while (!$post) {
                $post = strpos($text, '@', $pos + 1);
                if (strpos($text, $cmd, $pos) > $post || trim(substr($text, $pos + 1, $post - $pos - 1)) != $cmd) {
                    $pos = $post;
                    $post = false;
                } else {
                    $post = $post + 1;
                }
            }
            $front = $pos > 0 && $text[$pos - 1] == '{';
            $back = $post < strlen($text) && $text[$post] == '}';
            if (!($front && $back)) {
                $text = substr($text, 0, $pos) . $v4start . trim($cmd) . $v4end . substr($text, $post);
            }
            $i = $pos + strlen($v4start);
        }

		//fau: #37 Use ILIAS Insert LaTeX images and add alert if text has been changed
		if ($old_text != $text) {
			global $DIC;
			$lng = $DIC->language();
			ilUtil::sendInfo($lng->txt("qpl_qst_xqcas_update_to_version_3_2"), TRUE);

		}
		include_once './Services/MathJax/classes/class.ilMathJax.php';
		//ilMathJax::getInstance()->insertLatexImages cannot render \( delimiters so we change it to [tex]
		if ($start == '\(') {
			return ilMathJax::getInstance()->insertLatexImages($text);
		} else {
			return ilMathJax::getInstance()->insertLatexImages($text, $start, $end);
		}
		//fau.
    }
}
