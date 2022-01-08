<?php
/**
 * Copyright (c) 2022 Institut fuer Lern-Innovation, Friedrich-Alexander-Universitaet Erlangen-Nuernberg
 * GPLv2, see LICENSE
 */

/**
 * STACK Question Import from MoodleXML
 *
 * @author Jesus Copado <jesus.copado@fau.de>
 * @version $Id: 3.9$
 *
 */
require_once './Customizing/global/plugins/Modules/TestQuestionPool/Questions/assStackQuestion/classes/utils/class.assStackQuestionUtils.php';
require_once './Services/MediaObjects/classes/class.ilObjMediaObject.php';

class assStackQuestionMoodleImport
{
	/**
	 * Plugin instance for language management
	 * @var ilassStackQuestionPlugin
	 */
	private ilassStackQuestionPlugin $plugin;

	/**
	 * The current question
	 * @var assStackQuestion
	 */
	private assStackQuestion $question;

	/**
	 * Question_id for the first question to import
	 * (When only one question, use this as Question_Id)
	 * @var int If first question this var is higher than 0.
	 */
	private int $first_question;

	/**
	 * @var array
	 */
	private array $error_log = array();

	/**
	 * @var string    allowed html tags, e.g. "<em><strong>..."
	 */
	private string $rte_tags = "";

	/**
	 * media objects created for an imported question
	 * This list will be cleared for every new question
	 * @var array    id => object
	 */
	private array $media_objects = array();

	/**
	 * Set all the parameters for this question, including the creation of
	 * the first assStackQuestion object.
	 * @param ilassStackQuestionPlugin $plugin
	 * @param int $first_question_id the question_id for the first question to import.
	 * @param assStackQuestion $question
	 */
	function __construct(ilassStackQuestionPlugin $plugin, int $first_question_id, assStackQuestion $question)
	{
		//Set Plugin and first question id.
		$this->setPlugin($plugin);
		$this->setFirstQuestion($first_question_id);

		//Creation of the first question.
		$this->getPlugin()->includeClass('class.assStackQuestion.php');
		$this->setQuestion($question);

		//Initialization and load of stack wrapper classes
		$this->getPlugin()->includeClass('utils/class.assStackQuestionInitialization.php');
	}

	/* MAIN METHODS BEGIN */

	/**
	 * ### MAIN METHOD OF THIS CLASS ###
	 * This method is called from assStackQuestion to import the questions from an MoodleXML file.
	 * @param $xml_file
	 */
	public function import($xml_file)
	{
		//Step 1: Get data from XML.
		//LIBXML_NOCDATA Merge CDATA as Textnodes
		$xml = simplexml_load_file($xml_file, null, LIBXML_NOCDATA);

		//Step 2: Initialize question in ILIAS
		$number_of_questions_created = 0;

		foreach ($xml->question as $question) {

			//New list of media objects for each question
			$this->clearMediaObjects();

			//Set current question Id to -1 if we have created already one question, to ensure creation of the others
			if ($number_of_questions_created > 0) {
				$this->getQuestion()->setId(-1);
			}

			//Delete predefined inputs and prts
			$this->getQuestion()->inputs = array();
			$this->getQuestion()->prts = array();

			//If import process has been successful, save question to DB.
			if ($this->loadFromMoodleXML($question)) {

				//Save standard question data
				$this->getQuestion()->saveQuestionDataToDb();
				$this->getPlugin()->includeClass('class.assStackQuestionDB.php');
				try {
					//Save STACK Parameters forcing insert.
					if (assStackQuestionDB::_saveStackQuestion($this->getQuestion(), 'import')) {
						$this->saveMediaObjectUsages($this->getQuestion()->getId());
						$number_of_questions_created++;
					}
				} catch (stack_exception $e) {
					$this->error_log[] = 'question was not saved: ' . $this->getQuestion()->getTitle();
				}
			} else {
				//Do not create not well created questions
				//Send Error Message
				$error_message = '';
				foreach ($this->error_log as $error) {
					$error_message .= $error . '</br>';
				}
				ilUtil::sendFailure('faumiss Error message for malformed questions: ' . $this->getQuestion()->getTitle() . ' ' . $error_message, true);
				//Purge media objects as we didn't imported the question
				$this->purgeMediaObjects();
				//Delete Question
				$this->getQuestion()->delete($this->getQuestion()->getId());
			}
		}
	}

	/**
	 * Initializes $this->getQuestion with the values from the XML object.
	 * @param SimpleXMLElement $question
	 * @return bool
	 */
	public function loadFromMoodleXML(SimpleXMLElement $question): bool
	{
		//STEP 1: load standard question fields
		if (!isset($question->name->text) or $question->name->text == '') {
			$this->error_log[] = $this->getPlugin()->txt('error_import_no_title');
		}
		$question_title = (string)$question->name->text;

		if (!isset($question->questiontext->text) or $question->questiontext->text == '') {
			$this->error_log[] = $this->getPlugin()->txt('error_import_no_question_text') . ' in question: ' . $question_title;
		}
		$question_text = (string)$question->questiontext->text;

		if (!isset($question->defaultgrade) or $question->defaultgrade == '') {
			$this->error_log[] = $this->getPlugin()->txt('error_import_no_points') . ' in question: ' . $question_title;
		}
		$points = (string)$question->defaultgrade;

		//question text mapping for images
		if (isset($question->questiontext->file)) {
			$mapping = $this->getMediaObjectsFromXML($question->questiontext->file);
			$question_text = $this->replaceMediaObjectReferences($question_text, $mapping);
		}

		//set standard question fields as current.
		$this->getQuestion()->setTitle(ilUtil::secureString($question_title));
		$this->getQuestion()->setPoints(ilUtil::secureString($points));
		$this->getQuestion()->setQuestion(ilUtil::secureString($question_text));
		$this->getQuestion()->setLifecycle(ilAssQuestionLifecycle::getDraftInstance());

		//Save current values, to set the Id properly.
		$this->getQuestion()->saveQuestionDataToDb();

		//STEP 2: load xqcas_options fields

		//question variables
		if (isset($question->questionvariables->text)) {
			$this->getQuestion()->question_variables = ilUtil::secureString((string)$question->questionvariables->text);
		}

		//specific feedback
		$specific_feedback = (string)$question->specificfeedback->text;
		if (isset($question->specificfeedback->file)) {
			$mapping = $this->getMediaObjectsFromXML($question->specificfeedback->file);
			$specific_feedback = $this->replaceMediaObjectReferences($specific_feedback, $mapping);
		}
		$this->getQuestion()->specific_feedback = ilUtil::secureString($specific_feedback);

		$this->getQuestion()->specific_feedback_format = 1;

		//question note
		if (isset($question->questionnote->text)) {
			$this->getQuestion()->question_note = ilUtil::secureString((string)$question->questionnote->text);
		}

		//prt correct feedback
		$prt_correct = (string)$question->prtcorrect->text;
		if (isset($question->prtcorrect->file)) {
			$mapping = $this->getMediaObjectsFromXML($question->prtcorrect->file);
			$prt_correct = $this->replaceMediaObjectReferences($prt_correct, $mapping);
		}
		$this->getQuestion()->prt_correct = ilUtil::secureString($prt_correct);

		$this->getQuestion()->prt_correct_format = 1;

		//prt partially correct
		$prt_partially_correct = (string)$question->prtpartiallycorrect->text;
		if (isset($question->prtpartiallycorrect->file)) {
			$mapping = $this->getMediaObjectsFromXML($question->prtpartiallycorrect->file);
			$prt_partially_correct = $this->replaceMediaObjectReferences($prt_partially_correct, $mapping);
		}
		$this->getQuestion()->prt_partially_correct = ilUtil::secureString($prt_partially_correct);

		$this->getQuestion()->prt_partially_correct_format = 1;

		//prt incorrect
		$prt_incorrect = (string)$question->prtincorrect->text;
		if (isset($question->prtincorrect->file)) {
			$mapping = $this->getMediaObjectsFromXML($question->prtincorrect->file);
			$prt_incorrect = $this->replaceMediaObjectReferences($prt_incorrect, $mapping);
		}
		$this->getQuestion()->prt_incorrect = ilUtil::secureString($prt_incorrect);
		$this->getQuestion()->prt_incorrect_format = 1;

		//variants selection seeds
		$this->getQuestion()->variants_selection_seed = ilUtil::secureString((string)$question->variantsselectionseed);

		//options
		$options = array();
		$options['simplify'] = ((int)$question->questionsimplify);
		$options['assumepos'] = ((int)$question->assumepositive);
		$options['assumereal'] = ((int)$question->assumereal);
		$options['multiplicationsign'] = ilUtil::secureString((string)$question->multiplicationsign);
		$options['sqrtsign'] = ((int)$question->sqrtsign);
		$options['complexno'] = ilUtil::secureString((string)$question->complexno);
		$options['inversetrig'] = ilUtil::secureString((string)$question->inversetrig);
		$options['matrixparens'] = ilUtil::secureString((string)$question->matrixparens);
		$options['logicsymbol'] = ilUtil::secureString((string)$question->logicsymbol);

		//load options
		try {
			$this->getQuestion()->options = new stack_options($options);
			//set stack version
			if (isset($question->stackversion->text)) {
				$this->getQuestion()->stack_version = (string)ilUtil::secureString((string)$question->stackversion->text);
			}
		} catch (stack_exception $e) {
			$this->error_log[] = $question_title . ': options not created';
		}

		//STEP 3: load xqcas_inputs fields

		$required_parameters = stack_input_factory::get_parameters_used();

		//load all inputs present in the XML
		foreach ($question->input as $input) {

			$input_name = ilUtil::secureString((string)$input->name);
			$input_type = ilUtil::secureString((string)$input->type);

			$all_parameters = array(
				'boxWidth' => ilUtil::secureString((string)$input->boxsize),
				'strictSyntax' => ilUtil::secureString((string)$input->strictsyntax),
				'insertStars' => ilUtil::secureString((string)$input->insertstars),
				'syntaxHint' => ilUtil::secureString((string)$input->syntaxhint),
				'syntaxAttribute' => ilUtil::secureString((string)$input->syntaxattribute),
				'forbidWords' => ilUtil::secureString((string)$input->forbidwords),
				'allowWords' => ilUtil::secureString((string)$input->allowwords),
				'forbidFloats' => ilUtil::secureString((string)$input->forbidfloat),
				'lowestTerms' => ilUtil::secureString((string)$input->requirelowestterms),
				'sameType' => ilUtil::secureString((string)$input->checkanswertype),
				'mustVerify' => ilUtil::secureString((string)$input->mustverify),
				'showValidation' => ilUtil::secureString((string)$input->showvalidation),
				'options' => ilUtil::secureString((string)$input->options),
			);

			$parameters = array();
			foreach ($required_parameters[$input_type] as $parameter_name) {
				if ($parameter_name == 'inputType') {
					continue;
				}
				$parameters[$parameter_name] = $all_parameters[$parameter_name];
			}

			//load inputs
			try {
				$this->getQuestion()->inputs[$input_name] = stack_input_factory::make($input_type, $input_name, ilUtil::secureString((string)$input->tans), $this->getQuestion()->options, $parameters);
			} catch (stack_exception $e) {
				$this->error_log[] = $this->getQuestion()->getTitle() . ': ' . $e;
			}
		}

		//STEP 4:load PRTs and PRT nodes

		//Values
		$total_value = 0;

		foreach ($question->prt as $prt_data) {
			$total_value += (float)ilUtil::secureString((string)$prt_data->value);
		}

		if ($total_value < 0.0000001) {
			$total_value = 1.0;
		}

		foreach ($question->prt as $prt) {
			$first_node = 1;

			$prt_name = ilUtil::secureString((string)$prt->name);
			$nodes = array();
			$is_first_node = true;
			$invalid_node = false;

			//Check for non "0" nodes
			foreach ($prt->node as $xml_node) {
				if ($xml_node->name == '0') {
					$invalid_node = true;
				}
			}

			foreach ($prt->node as $xml_node) {
				//Check for non "0" nodes
				if ($invalid_node) {
					$new_node_name = ((int)$xml_node->name) + 1;
					$node_name = ilUtil::secureString((string)$new_node_name);
				} else {
					$node_name = ilUtil::secureString((string)$xml_node->name);
				}

				$raw_sans = ilUtil::secureString((string)$xml_node->sans);
				$raw_tans = ilUtil::secureString((string)$xml_node->tans);

				$sans = stack_ast_container::make_from_teacher_source('PRSANS' . $node_name . ':' . $raw_sans, '', new stack_cas_security());
				$tans = stack_ast_container::make_from_teacher_source('PRTANS' . $node_name . ':' . $raw_tans, '', new stack_cas_security());

				//Penalties management, penalties are not an ILIAS Feature
				$false_penalty = ilUtil::secureString((string)$xml_node->falsepenalty);
				$true_penalty = ilUtil::secureString((string)$xml_node->truepenalty);

				try {
					//Create Node and add it to the
					$node = new stack_potentialresponse_node($sans, $tans, ilUtil::secureString((string)$xml_node->answertest), ilUtil::secureString((string)$xml_node->testoptions), (bool)$xml_node->quiet, '', (int)$node_name, $raw_sans, $raw_tans);

					//manage images in false feedback
					$false_feedback = (string)$xml_node->falsefeedback->text;
					if (isset($xml_node->falsefeedback->file)) {
						$mapping = $this->getMediaObjectsFromXML($xml_node->falsefeedback->file);
						$false_feedback = $this->replaceMediaObjectReferences($false_feedback, $mapping);
					}

					//manage images in true feedback
					$true_feedback = (string)$xml_node->truefeedback->text;
					if (isset($xml_node->truefeedback->file)) {
						$mapping = $this->getMediaObjectsFromXML($xml_node->truefeedback->file);
						$true_feedback = $this->replaceMediaObjectReferences($true_feedback, $mapping);
					}

					//Check for non "0" next nodes
					$true_next_node = $xml_node->truenextnode;
					$false_next_node = $xml_node->falsenextnode;

					//If certain nodes point node 0 as next node (not usual)
					//The next node will now be -1, so, end of the prt.
					//If we are already in node 1, we cannot point ourselves
					if ($true_next_node == '-1') {
						$true_next_node = -1;
					} else {
						$true_next_node = $true_next_node + 1;
					}

					if ($false_next_node == '-1') {
						$false_next_node = -1;
					} else {
						$false_next_node = $false_next_node + 1;
					}

					//Check for non "0" answer notes
					if ($invalid_node) {
						$true_answer_note = $prt_name . '-' . $node_name . '-T';
						$false_answer_note = $prt_name . '-' . $node_name . '-F';
					} else {
						$true_answer_note = $xml_node->trueanswernote;
						$false_answer_note = $xml_node->falseanswernote;
					}

					$node->add_branch(0, ilUtil::secureString((string)$xml_node->falsescoremode), ilUtil::secureString((string)$xml_node->falsescore), $false_penalty, ilUtil::secureString((string)$false_next_node), $false_feedback, 1, ilUtil::secureString((string)$false_answer_note));
					$node->add_branch(1, ilUtil::secureString((string)$xml_node->truescoremode), ilUtil::secureString((string)$xml_node->truescore), $true_penalty, ilUtil::secureString((string)$true_next_node), $true_feedback, 1, ilUtil::secureString((string)$true_answer_note));

					$nodes[$node_name] = $node;

					//set first node
					if ($is_first_node) {
						$first_node = $node_name;
						$is_first_node = false;
					}

				} catch (stack_exception $e) {
					$this->error_log[] = $this->getQuestion()->getTitle() . ': ' . $e;
				}
			}

			$feedback_variables = null;
			if ((string)$prt->feedbackvariables->text) {
				try {
					$feedback_variables = new stack_cas_keyval(ilUtil::secureString((string)$prt->feedbackvariables->text));
					$feedback_variables = $feedback_variables->get_session();
				} catch (stack_exception $e) {
					$this->error_log[] = $this->getQuestion()->getTitle() . ': ' . $e;
				}
			}

			$prt_value = (float)$prt->value / $total_value;

			try {
				$this->getQuestion()->prts[$prt_name] = new stack_potentialresponse_tree($prt_name, '', (bool)$prt->autosimplify, $prt_value, $feedback_variables, $nodes, (string)$first_node, 1);
			} catch (stack_exception $e) {
				$this->error_log[] = $this->getQuestion()->getTitle() . ': ' . $e;
			}
		}

		//seeds:
		$seeds = array();
		if (isset($question->deployedseed)) {
			foreach ($question->deployedseed as $seed) {
				$seeds[] = (int)ilUtil::secureString((string)$seed);
			}
		}
		$this->getQuestion()->deployed_seeds = $seeds;

		if (empty($this->error_log)) {
			return true;
		} else {
			return false;
		}
	}

	/* MAIN METHODS END */

	/* HELPER METHODS BEGIN */

	/**
	 * Create media objects from array converted file elements
	 * @param SimpleXMLElement $data [['_attributes' => ['name' => string, 'path' => string], '_content' => string], ...]
	 * @return    array             filename => object_id
	 */
	private function getMediaObjectsFromXML(SimpleXMLElement $data): array
	{
		$mapping = array();
		foreach ($data as $file) {
			$name = $file['_attributes']['name'];
			//$path = $file['_attributes']['path'];
			$src = $file['_content'];

			$temp = ilUtil::ilTempnam();
			file_put_contents($temp, base64_decode($src));
			$media_object = ilObjMediaObject::_saveTempFileAsMediaObject($name, $temp, false);
			@unlink($temp);

			$this->media_objects[$media_object->getId()] = $media_object;
			$mapping[$name] = $media_object->getId();
		}

		return $mapping;
	}

	/**
	 * Replace references to media objects in a text
	 * @param string    text from moodleXML with local references
	 * @param array    mapping of filenames to media object IDs
	 * @return    string    text with paths to media objects
	 */
	private function replaceMediaObjectReferences($text = "", $mapping = array()): string
	{
		foreach ($mapping as $name => $id) {
			$text = str_replace('src="@@PLUGINFILE@@/' . $name, 'src="' . ILIAS_HTTP_PATH . '/data/' . CLIENT_ID . '/mobs/mm_' . $id . "/" . $name . '"', $text);
		}

		return $text;
	}

	/**
	 * Clear the list of media objects
	 * This should be called for every new question import
	 */
	private function clearMediaObjects()
	{
		$this->media_objects = array();
	}

	/**
	 * Save the usages of media objects in a question
	 * @param integer $question_id
	 */
	private function saveMediaObjectUsages(int $question_id)
	{
		foreach ($this->media_objects as $media_object) {
			ilObjMediaObject::_saveUsage($media_object->getId(), "qpl:html", $question_id);
		}
		$this->media_objects = array();
	}

	/**
	 * Purge the media objects collected for a not imported question
	 */
	private function purgeMediaObjects()
	{
		foreach ($this->media_objects as $media_object) {
			$media_object->delete();
		}
		$this->media_objects = array();
	}

	/* HELPER METHODS END */

	/* GETTERS AND SETTERS BEGIN */

	/**
	 * @param ilassStackQuestionPlugin $plugin
	 */
	public function setPlugin(ilassStackQuestionPlugin $plugin)
	{
		$this->plugin = $plugin;
	}

	/**
	 * @return ilassStackQuestionPlugin
	 */
	public function getPlugin(): ilassStackQuestionPlugin
	{
		return $this->plugin;
	}

	/**
	 * @param assStackQuestion $question
	 */
	public function setQuestion(assStackQuestion $question)
	{
		$this->question = $question;
	}

	/**
	 * @return assStackQuestion
	 */
	public function getQuestion(): assStackQuestion
	{
		return $this->question;
	}

	/**
	 * @param int $first_question
	 */
	public function setFirstQuestion(int $first_question)
	{
		$this->first_question = $first_question;
	}

	/**
	 * @return int
	 */
	public function getFirstQuestion(): int
	{
		return $this->first_question;
	}

	/**
	 * @param $tags
	 */
	public function setRTETags($tags)
	{
		$this->rte_tags = $tags;
	}

	/**
	 * @return string    allowed html tags, e.g. "<em><strong>..."
	 */
	public function getRTETags(): string
	{
		return $this->rte_tags;
	}

	/* GETTERS AND SETTERS END */

	/*

	private function getTestsFromXML($data)
	{
		$this->getPlugin()->includeClass('model/ilias_object/test/class.assStackQuestionTest.php');
		$tests = array();

		foreach ($data as $test) {
			//Main attributes needed to create an TestOBJ
			$test_case = (int)$test['testcase'];
			$new_test = new assStackQuestionTest(-1, $this->getQuestion()->getId(), $test_case);

			//Creation of inputs
			$test_inputs = $this->getTestInputsFromXML($test['testinput'], $this->getQuestion()->getId(), $test_case);
			$new_test->setTestInputs($test_inputs);

			//Creation of expected results
			$test_expected = $this->getTestExpectedFromXML($test['expected'], $this->getQuestion()->getId(), $test_case);
			$new_test->setTestExpected($test_expected);

			$tests[] = $new_test;
		}

		//array of assStackQuestionTest
		return $tests;
	}

	private function getTestInputsFromXML($data, $question_id, $test_case)
	{
		$this->getPlugin()->includeClass('model/ilias_object/test/class.assStackQuestionTestInput.php');
		$test_inputs = array();

		foreach ($data as $input) {
			$new_test_input = new assStackQuestionTestInput(-1, $this->getQuestion()->getId(), $test_case);

			$new_test_input->setTestInputName($input['name']);
			$new_test_input->setTestInputValue($input['value']);

			$test_inputs[] = $new_test_input;
		}

		//array of assStackQuestionTestInput
		return $test_inputs;
	}

	private function getTestExpectedFromXML($data, $question_id, $test_case)
	{
		$this->getPlugin()->includeClass('model/ilias_object/test/class.assStackQuestionTestExpected.php');
		$test_expected = array();

		foreach ($data as $expected) {
			//Getting the PRT name
			$prt_name = strip_tags($expected['name']);
			$new_test_expected = new assStackQuestionTestExpected(-1, $this->getQuestion()->getId(), $test_case, $prt_name);

			$new_test_expected->setExpectedScore(strip_tags($expected['expectedscore']));
			$new_test_expected->setExpectedPenalty(strip_tags($expected['expectedpenalty']));
			$new_test_expected->setExpectedAnswerNote($expected['expectedanswernote']);

			$test_expected[] = $new_test_expected;
		}

		//array of assStackQuestionTestExpected
		return $test_expected;
	}


	private function getExtraInfoFromXML($data)
	{
		$this->getPlugin()->includeClass('model/ilias_object/class.assStackQuestionExtraInfo.php');
		$extra_info = new assStackQuestionExtraInfo(-1, $this->getQuestion()->getId());

		//General feedback property
		$mapping = $this->getMediaObjectsFromXML($data['generalfeedback'][0]['file']);
		$how_to_solve = assStackQuestionUtils::_casTextConverter($this->replaceMediaObjectReferences($data['generalfeedback'][0]['text'], $mapping), $this->getQuestion()->getTitle(), TRUE);
		$extra_info->setHowToSolve(ilUtil::secureString($how_to_solve, true, $this->getRTETags()));
		//Penalty property
		$penalty = $data['penalty'];
		$extra_info->setPenalty($penalty);
		//Hidden property
		$hidden = $data['hidden'];
		$extra_info->setHidden($hidden);

		//assStackQuestionExtraInfo
		return $extra_info;
	}

	public function php72Format($raw_data)
	{
		$full_data = array();

		foreach ($raw_data as $question_data) {
			$data = array();
			//Check for not category
			if (is_array($question_data['category'])) {
				continue;
			}
			//qtest
			if (is_array($question_data['qtest'])) {
				foreach ($question_data['qtest'] as $qtest_raw) {
					$qtest_data = array();

					//testcase
					if (isset($qtest_raw['testcase'][0]["_content"])) {
						$qtest_data['testcase'] = $qtest_raw['testcase'][0]["_content"];
					} else {
						$qtest_data['testcase'] = "";
					}

					//testinput
					if (isset($qtest_raw['testinput'][0]['name'][0]["_content"]) and isset($qtest_raw['testinput'][0]['value'][0]["_content"])) {
						$qtest_data['testinput'][0]['name'] = $qtest_raw['testinput'][0]['name'][0]["_content"];
						$qtest_data['testinput'][0]['value'] = $qtest_raw['testinput'][0]['value'][0]["_content"];
					} else {
						$qtest_data['testinput'][0]['name'] = "";
						$qtest_data['testinput'][0]['value'] = "";
					}

					//expected
					if (isset($qtest_raw['expected'][0]['name'][0]["_content"]) and isset($qtest_raw['expected'][0]['expectedscore'][0]["_content"]) and isset($qtest_raw['expected'][0]['expectedanswernote'][0]["_content"])) {
						$qtest_data['expected'][0]['name'] = $qtest_raw['expected'][0]['name'][0]["_content"];
						$qtest_data['expected'][0]['expectedscore'] = $qtest_raw['expected'][0]['expectedscore'][0]["_content"];
						$qtest_data['expected'][0]['expectedpenalty'] = $qtest_raw['expected'][0]['expectedpenalty'][0]["_content"];
						$qtest_data['expected'][0]['expectedanswernote'] = $qtest_raw['expected'][0]['expectedanswernote'][0]["_content"];

					} else {
						$qtest_data['expected'][0]['name'] = "";
						$qtest_data['expected'][0]['expectedscore'] = "";
						$qtest_data['expected'][0]['expectedpenalty'] = "";
						$qtest_data['expected'][0]['expectedanswernote'] = "";
					}

					//Add to question
					$data['qtest'][] = $qtest_data;
				}
			}

			//Add to full data
			$full_data['question'][] = $data;
		}

		return $full_data;
	}


	public function checkQuestion(assStackQuestion $question)
	{
		//Step 1: Check if there is one option object and at least one input, one prt with at least one node;
		if (!is_a($question->getOptions(), 'assStackQuestionOptions')) {
			return false;
		}
		if (is_array($question->getInputs())) {
			foreach ($question->getInputs() as $input) {
				if (!is_a($input, 'assStackQuestionInput')) {
					return false;
				}
			}
		} else {
			return false;
		}
		if (is_array($question->getPotentialResponsesTrees())) {
			foreach ($question->getPotentialResponsesTrees() as $prt) {
				if (!is_a($prt, 'assStackQuestionPRT')) {
					return false;
				} else {
					foreach ($prt->getPRTNodes() as $node) {
						if (!is_a($node, 'assStackQuestionPRTNode')) {
							return false;
						}
					}
				}
			}
		} else {
			return false;
		}

		//Step 2: Check options
		$options_are_ok = $question->getOptions()->checkOptions(TRUE);

		//Step 3: Check inputs
		foreach ($question->getInputs() as $input) {
			$inputs_are_ok = $input->checkInput(TRUE);
			if ($inputs_are_ok == FALSE) {
				break;
			}
		}

		//Step 4A: Check PRT
		if (is_array($question->getPotentialResponsesTrees())) {
			foreach ($question->getPotentialResponsesTrees() as $PRT) {
				$PRTs_are_ok = $PRT->checkPRT(TRUE);
				if ($PRTs_are_ok == FALSE) {
					break;
				} else {
					//Step 4B: Check Nodes
					if (is_array($PRT->getPRTNodes())) {
						foreach ($PRT->getPRTNodes() as $node) {
							$Nodes_are_ok = $node->checkPRTNode(TRUE);
							if ($Nodes_are_ok == FALSE) {
								break;
							}
						}
					}
					//Step 4C: Check if nodes make a PRT
				}
			}
		}

		//Step 5: Check tests
		if (!empty($question->getTests())) {
			foreach ($question->getTests() as $test) {
				if (!is_a($test, 'assStackQuestionTest')) {
					return false;
				} else {
					$tests_creation_is_ok = $test->checkTest(TRUE);
					//Step 5B: Check inputs
					foreach ($test->getTestInputs() as $input) {
						$test_inputs_are_ok = $input->checkTestInput(TRUE);
						if ($test_inputs_are_ok == FALSE) {
							break;
						}
					}
					//Step 5C: Check expected
					foreach ($test->getTestExpected() as $expected) {
						$test_expected_are_ok = $expected->checkTestExpected(TRUE);
						if ($test_expected_are_ok == FALSE) {
							break;
						}
					}
					if ($tests_creation_is_ok and $test_inputs_are_ok and $test_expected_are_ok) {
						$test_are_ok = TRUE;
					} else {
						$test_are_ok = FALSE;
					}
				}
			}
		} else {
			$test_are_ok = TRUE;
		}

		if ($options_are_ok and $inputs_are_ok and $PRTs_are_ok and $Nodes_are_ok and $test_are_ok) {
			return true;
		} else {
			return false;
		}
	}

	*/

}
