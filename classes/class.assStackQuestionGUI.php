<?php
/**
 * Copyright (c) Laboratorio de Soluciones del Sur, Sociedad Limitada
 * GPLv3, see LICENSE
 */


/**
 * STACK Question GUI
 *
 * @author Jesús Copado Mejías <stack@surlabs.es>
 * @version $Id: 7.1$
 * @ingroup    ModulesTestQuestionPool
 * @ilCtrl_isCalledBy assStackQuestionGUI: ilObjQuestionPoolGUI, ilObjTestGUI, ilQuestionEditGUI, ilTestExpressPageObjectGUI
 * @ilCtrl_Calls assStackQuestionGUI: ilFormPropertyDispatchGUI
 *
 */
class assStackQuestionGUI extends assQuestionGUI
{

	/**
	 * @var ilassStackQuestionPlugin
	 */
	private ilassStackQuestionPlugin $plugin;

	//RTE Support variables

	/**
	 *
	 * @var string
	 */
	protected string $rte_module = "xqcas";

	/**
	 * @var array
	 */
	protected array $rte_tags = array();

	/**
	 * Stores the preview data while on preview mode
	 * Otherwise empty
	 * @var array
	 */
	private array $is_preview;

	/**
	 * assStackQuestionGUI constructor.
	 */
	public function __construct($id = -1)
	{
        parent::__construct();

        //Initialize plugin object
        require_once './Services/Component/classes/class.ilPlugin.php';
        try {
            $plugin = ilPlugin::getPluginObject(IL_COMP_MODULE, 'TestQuestionPool', 'qst', 'assStackQuestion');
            if (!is_a($plugin, 'ilassStackQuestionPlugin')) {
                ilUtil::sendFailure('Not ilassStackQuestionPlugin object', true);
            } else {
                $this->setPlugin($plugin);
            }
        } catch (ilPluginException $e) {
            ilUtil::sendFailure($e, true);
        }

        //Initialize and loads the Stack question from DB
        $this->object = new assStackQuestion();

        if ($id >= 0) {
            try {
                $this->object->loadFromDb($id);
            } catch (stack_exception $e) {
                ilUtil::sendFailure($e, true);
            }
        }
        //Initialize some STACK required parameters
        include_once './Customizing/global/plugins/Modules/TestQuestionPool/Questions/assStackQuestion/classes/utils/class.assStackQuestionInitialization.php';
	}

	/**
	 * Returns the HTML for the Test View
	 * @param $active_id
	 * @param $pass
	 * @param $is_question_postponed
	 * @param $user_post_solutions
	 * @param $show_specific_inline_feedback
	 * @return false|mixed|string|void|null
	 */
	public function getTestOutput($active_id, $pass, $is_question_postponed, $user_post_solutions, $show_specific_inline_feedback)
	{
		//Question initialization
		$seed = assStackQuestionDB::_getSeedForTestPass($this->object, $active_id, $pass);

		if (!$this->object->isInstantiated()) {
			$this->object->questionInitialisation($seed, true);
		}

		//Get user solution from DB
		if (empty($user_solution_from_db = $this->object->getTestOutputSolutions($active_id, $pass))) {


			//No user Solution
			//Render question from scratch
			$this->getPlugin()->includeClass('class.assStackQuestionRenderer.php');
			try {
				//Return question output
				$question_output = assStackQuestionRenderer::_renderQuestionText($this->object, $show_specific_inline_feedback);
				return $this->outQuestionPage('', $is_question_postponed, $active_id, $question_output, $show_specific_inline_feedback);
			} catch (stack_exception $e) {
				return $e->getMessage();
			}

		} else {
            //Use user solution from DB
            $user_solution = array();
            //Get user solution from DB
            foreach ($this->object->inputs as $input_name => $input) {

                //first adaptation of the user solution only if user solution is present
                $user_solution[$input_name] = $user_solution_from_db['inputs'][$input_name]['value'];

                if (is_a($input, 'stack_textarea_input')
                    or is_a($input, 'stack_equiv_input')
                    or is_a($input, 'stack_matrix_input')) {

                    $response = $input->maxima_to_response_array($user_solution[$input_name]);

                    //clean solution
                    foreach (array_keys($response) as $array_key) {
                        if (strpos($array_key, '_val')) {
                            unset($response[$array_key]);
                        }
                    }

                    if(isset($user_solution[$input_name])){
                        unset($user_solution[$input_name]);
                    }

                    $user_solution = array_merge($user_solution, $response);
                } elseif (is_a($input, 'stack_checkbox_input')) {
                    $response = $input->maxima_to_response_array($user_solution[$input_name]);
                    $user_solution = array_merge($user_solution, $response);
                } elseif (is_a($input, 'stack_dropdown_input')
                    or is_a($input, 'stack_radio_input')) {
                    $response = $input->maxima_to_response_array($user_solution[$input_name]);
                    $user_solution[$input_name] = $response[$input_name];
                }
            }
        }

        $this->object->setUserResponse($user_solution);

        //Ensure evaluation has been done
        if (empty($this->object->getEvaluation())) {
            $this->object->evaluateQuestion($this->object->getUserResponse());
        }

        //Render Question
		$this->getPlugin()->includeClass('class.assStackQuestionRenderer.php');
		try {
			//$question_output = assStackQuestionRenderer::_renderQuestionTest($this->object, $active_id, $pass, $user_post_solutions, $show_specific_inline_feedback, $is_question_postponed);
			$question_output = assStackQuestionRenderer::_renderQuestionText($this->object, $show_specific_inline_feedback);
			//Return question output
			return $this->outQuestionPage('', $is_question_postponed, $active_id, $question_output, $show_specific_inline_feedback);
		} catch (stack_exception $e) {
			return $e->getMessage();
		}
	}

	/**
	 * Returns question view with correct response filled in
	 * @param integer $active_id The active user id
	 * @param integer|null $pass The test pass
	 * @param boolean $graphicalOutput Show visual feedback for right/wrong answers
	 * @param boolean $result_output Show the reached points for parts of the question
	 * @param boolean $show_question_only Show the question without the ILIAS content around
	 * @param boolean $show_feedback Show the question feedback
	 * @param boolean $show_correct_solution Show the correct solution instead of the user solution
	 * @param boolean $show_manual_scoring Show specific information for the manual scoring output
	 * @param bool $show_question_text
	 * @return string
	 */
	public function getSolutionOutput($active_id, $pass = null, $graphicalOutput = false, $result_output = false, $show_question_only = true, $show_feedback = false, $show_correct_solution = false, $show_manual_scoring = false, $show_question_text = true): string
	{
		$this->getPlugin()->includeClass('class.assStackQuestionRenderer.php');

		//Llama dos veces, una para el texto y otra para la best solution
		if (!$this->object->isInstantiated()) {
			//Not in preview, not in test run, we are in Test Results
			//Check for PASS

			require_once './Modules/Test/classes/class.ilObjTest.php';
			if (!ilObjTest::_getUsePreviousAnswers($active_id, true)) {
				if (is_null($pass)) {
					$pass = ilObjTest::_getPass($active_id);
				}
			}
			//Return Solution output for Test Results
			//Raw replacement from tst_solution instead of instancing and evaluate question
			if (!$show_correct_solution) {
				//TEXT
				$solution_output = assStackQuestionRenderer::_renderQuestionTextForTestResults($this->object, $active_id, $pass);
			} else {
				//SOLUTION
				$solution_output = assStackQuestionRenderer::renderBestSolutionForTestResults($this->object, $active_id, $pass);
			}

			if (!$show_question_only) {
				// get page object output
				$solution_output = $this->getILIASPage($solution_output);
			}

			return $solution_output;
		}

		$solution_output = assStackQuestionRenderer::_renderBestSolution($this->object);

		//Return Solution output
		if (!$show_question_only) {
			// get page object output
			$solution_output = $this->getILIASPage($solution_output);
		}

		return $solution_output;
	}

	/**
	 * Returns the HTML for the question Preview
	 * @param bool $show_question_only
	 * @param bool $showInlineFeedback
	 * @return string HTML
	 */
	public function getPreview($show_question_only = false, $showInlineFeedback = false): string
	{
		global $DIC;

		//set preview mode
		$this->setIsPreview(array(1));

		//User response from session
		$user_solution = array();
		//Debug the PreviewSession Data
		if (is_object($this->getPreviewSession())) {
            $raw_participants_solution = (array) $this->getPreviewSession()->getParticipantsSolution();
            foreach ($raw_participants_solution as $key => $value) {
                if (version_compare(phpversion(), '8.0.0', '<')) {
                    if (substr($key, 0, 13) !== 'xqcas_solution') {
                        $user_solution[$key] = $value;
                    }
                } else {
                    if (!str_starts_with($key, 'xqcas_solution')) {
                        $user_solution[$key] = $value;
                    }
                }
            }
		}
		//Seed management
		if (isset($_REQUEST['fixed_seed'])) {
			$seed = $_REQUEST['fixed_seed'];
			$_SESSION['q_seed_for_preview_' . $this->object->getId() . ''] = $seed;
		} else {
			if (isset($_SESSION['q_seed_for_preview_' . $this->object->getId() . ''])) {
				$seed = $_SESSION['q_seed_for_preview_' . $this->object->getId() . ''];
			} else {
				$seed = -1;
			}
		}

		//Initialise the question
		if (!$this->object->isInstantiated()) {

			//if fixed_seed is activate, we are in preview forcing the use of a certain seed for this session
			if (isset($_REQUEST['fixed_seed'])) {
				$variant = $_REQUEST['fixed_seed'];
			} else {
				//Variant management
				if (isset($_SESSION['q_seed_for_preview_' . $this->object->getId() . ''])) {
					//We do have already a seed
					$variant = (int)$_SESSION['q_seed_for_preview_' . $this->object->getId() . ''];
				} else {
					//We need a seed
					if (!$this->object->hasRandomVariants()) {
						// Randomisation not used.
						$variant = 1;
					} else if (!empty($this->object->deployed_seeds)) {
						//If there are variants
						//Choose between deployed seeds
						$chosen_seed = array_rand($this->object->deployed_seeds);
						//Set random selected seed
						$variant = (int)$chosen_seed;
					} else {
						//Complete randomisation
						if ($this->object->hasRandomVariants()) {
							$variant = rand(1111111111, 9999999999);
						} else {
							$variant = 1;
						}
					}
				}
			}

			$_SESSION['q_seed_for_preview_' . $this->object->getId() . ''] = $variant;
			$this->object->questionInitialisation($variant, true);
		}

		$response = array();
		foreach ($this->object->inputs as $input_name => $input) {
            //Do not send matrix to maxima
            if (is_a($input, 'stack_matrix_input')
                or is_a($input, 'stack_textarea_input')
                or is_a($input, 'stack_equiv_input')) {
                //clean solution
                foreach (array_keys($user_solution) as $array_key){
                    if (strpos($array_key, '_solution_')) {
                        unset($user_solution[$array_key]);
                    }
                }
                $this->object->setUserResponse($user_solution);
            } elseif (is_a($input, 'stack_dropdown_input')
                or is_a($input, 'stack_radio_input')
                or is_a($input, 'stack_checkbox_input')) {
                $this->object->setUserResponse($user_solution);
            } else {
                $response[$input_name] = $input->contents_to_maxima($input->response_to_contents($user_solution));
                $this->object->setUserResponse($response,$input_name);
            }
        }


		//Ensure evaluation has been done
		if (empty($this->object->getEvaluation())) {
			$this->object->evaluateQuestion($this->object->getUserResponse());
		}

		//Render question Preview
		$this->getPlugin()->includeClass('class.assStackQuestionRenderer.php');
		$question_preview = assStackQuestionRenderer::_renderQuestionText($this->object);

		//Tab management
		$tabs = $DIC->tabs();
		if ($_GET['cmd'] == 'edit') {
			$tabs->activateTab('edit_page');
		} elseif ($_GET['cmd'] == 'preview') {
			$tabs->activateTab('preview');
		}

		//Returns output (with page if needed)
		if (!$show_question_only) {
			// get page object output
			$question_preview = $this->getILIASPage($question_preview);
		}

		return $question_preview;
	}

	/**
	 * Returns the HTML for the specific feedback output
	 * @param $userSolution
	 * @return string HTML Code with the rendered specific feedback text including the general feedback
	 */
	public function getSpecificFeedbackOutput($userSolution): string
	{

		$this->getPlugin()->includeClass('class.assStackQuestionRenderer.php');

		//Include content Style
		$style_id = assStackQuestionUtils::_getActiveContentStyleId();
		if (strlen($style_id)) {
			require_once "./Services/Style/Content/classes/class.ilObjStyleSheet.php";
			global $DIC;
			$DIC->globalScreen()->layout()->meta()->addCss(ilObjStyleSheet::getContentStylePath((int)$style_id));
		}

		$general_feedback = assStackQuestionRenderer::_renderGeneralFeedback($this->object);

		if (isset($this->is_preview)) {

			//Ensure evaluation has been done
			if (empty($this->object->getEvaluation())) {
				$this->object->evaluateQuestion($userSolution);
			}

			$specific_feedback = assStackQuestionRenderer::_renderFeedbackForPreview($this->object);
		} else {
			$specific_feedback = assStackQuestionRenderer::_renderFeedbackForTest($this->object, $userSolution);
		}

		return $general_feedback . $specific_feedback;
	}

	/**
	 * Evaluates a posted edit form and writes the form data in the question object
	 * (called frm generic commands in assQuestionGUI)
	 * Converts the data from post into assStackQuestion ($this->object)
	 * Called before editQuestion()
	 *
	 * @return integer    0: question can be saved / 1: form is not complete
	 */
	public function writePostData($always = FALSE): int
	{
		$hasErrors = !$always && $this->editQuestion(TRUE);
		if (!$hasErrors) {

			$this->questionCheck();

			//Parent
			$this->writeQuestionGenericPostData();
			$this->writeQuestionSpecificPostData();

			//Taxonomies
			$this->saveTaxonomyAssignments();

			return 0;
		}
		return 1;
	}

	/**
	 * Writes the data from $_POST into assStackQuestion
	 * Called before editQuestion()
	 */
	public function writeQuestionSpecificPostData()
	{
		require_once("./Services/RTE/classes/class.ilRTE.php");

		//Question Text - Reload it with RTE (already loaded in writeQuestionGenericPostData())
		$question_text = ((isset($_POST['question']) and $_POST['question'] != null) ? ilUtil::stripSlashes($_POST['question'], true, $this->getRTETags()) : '');
		$this->object->setQuestion(ilRTE::_replaceMediaObjectImageSrc($question_text, 1));

		//stack_options
		$options = array();
		$options['simplify'] = ((isset($_POST['options_question_simplify']) and $_POST['options_question_simplify'] != null) ? (int)trim(ilUtil::secureString($_POST['options_question_simplify'])) : '');
		$options['assumepos'] = ((isset($_POST['options_assume_positive']) and $_POST['options_assume_positive'] != null) ? (int)trim(ilUtil::secureString($_POST['options_assume_positive'])) : '');
		$options['multiplicationsign'] = ((isset($_POST['options_multiplication_sign']) and $_POST['options_multiplication_sign'] != null) ? trim(ilUtil::secureString($_POST['options_multiplication_sign'])) : '');
		$options['sqrtsign'] = ((isset($_POST['options_sqrt_sign']) and $_POST['options_sqrt_sign'] != null) ? (int)trim(ilUtil::secureString($_POST['options_sqrt_sign'])) : '');
		$options['complexno'] = ((isset($_POST['options_complex_numbers']) and $_POST['options_complex_numbers'] != null) ? trim(ilUtil::secureString($_POST['options_complex_numbers'])) : '');
		$options['inversetrig'] = ((isset($_POST['options_inverse_trigonometric']) and $_POST['options_inverse_trigonometric'] != null) ? trim(ilUtil::secureString($_POST['options_inverse_trigonometric'])) : '');
		$options['matrixparens'] = ((isset($_POST['options_matrix_parens']) and $_POST['options_matrix_parens'] != null) ? $_POST['options_matrix_parens'] : '');

		try {
			$options = new stack_options($options);
			//SET OPTIONS
			$this->object->options = $options;
		} catch (stack_exception $e) {
			ilUtil::sendFailure($e, true);
		}

		//Load data sent as options but not part of the session options
		$this->object->question_variables = ((isset($_POST['options_question_variables']) and $_POST['options_question_variables'] != null) ? assStackQuestionUtils::_debugText($_POST['options_question_variables']) : '');
		$this->object->question_note = ((isset($_POST['options_question_note']) and $_POST['options_question_note'] != null) ? ilUtil::secureString($_POST['options_question_note']) : '');

		$this->object->specific_feedback = ((isset($_POST['options_specific_feedback']) and $_POST['options_specific_feedback'] != null) ? ilUtil::stripSlashes($_POST['options_specific_feedback'], true, $this->getRTETags()) : '');
		$this->object->specific_feedback_format = 1;

		$this->object->prt_correct = ((isset($_POST['options_prt_correct']) and $_POST['options_prt_correct'] != null) ? ilUtil::stripSlashes($_POST['options_prt_correct'], true, $this->getRTETags()) : '');
		$this->object->prt_correct_format = 1;
		$this->object->prt_partially_correct = ((isset($_POST['options_prt_partially_correct']) and $_POST['options_prt_partially_correct'] != null) ? ilUtil::stripSlashes($_POST['options_prt_partially_correct'], true, $this->getRTETags()) : '');
		$this->object->prt_partially_correct_format = 1;
		$this->object->prt_incorrect = ((isset($_POST['options_prt_incorrect']) and $_POST['options_prt_incorrect'] != null) ? ilUtil::stripSlashes($_POST['options_prt_incorrect'], true, $this->getRTETags()) : '');
		$this->object->prt_incorrect_format = 1;

		$this->object->general_feedback = ((isset($_POST['options_how_to_solve']) and $_POST['options_how_to_solve'] != null) ? ilUtil::stripSlashes($_POST['options_how_to_solve'], true, $this->getRTETags()) : '');

		//TODO
		//$this->object->variants_selection_seed = ?;

		//stack_inputs
		$required_parameters = stack_input_factory::get_parameters_used();

		//load only those inputs appearing in the question text
		foreach (stack_utils::extract_placeholders($this->object->getQuestion(), 'input') as $input_name) if (isset($_POST[$input_name . '_input_type'])) {

			$type = ((isset($_POST[$input_name . '_input_type']) and $_POST[$input_name . '_input_type'] != null) ? trim(ilUtil::secureString($_POST[$input_name . '_input_type'])) : '');

			$all_parameters = array(
				'boxWidth' => ((isset($_POST[$input_name . '_input_box_size']) and $_POST[$input_name . '_input_box_size'] != null) ? trim(ilUtil::secureString($_POST[$input_name . '_input_box_size'])) : ''),
				'strictSyntax' => ((isset($_POST[$input_name . '_input_strict_syntax']) and $_POST[$input_name . '_input_strict_syntax'] != null) ? trim(ilUtil::secureString($_POST[$input_name . '_input_strict_syntax'])) : ''),
				'insertStars' => ((isset($_POST[$input_name . '_input_insert_stars']) and $_POST[$input_name . '_input_insert_stars'] != null) ? trim(ilUtil::secureString($_POST[$input_name . '_input_insert_stars'])) : ''),
				'syntaxHint' => ((isset($_POST[$input_name . '_input_syntax_hint']) and $_POST[$input_name . '_input_syntax_hint'] != null) ? trim(ilUtil::secureString($_POST[$input_name . '_input_syntax_hint'])) : ''),
				'syntaxAttribute' => ((isset($_POST[$input_name . '_input_syntax_attribute']) and $_POST[$input_name . '_input_syntax_attribute'] != null) ? trim(ilUtil::secureString($_POST[$input_name . '_input_syntax_attribute'])) : ''),
				'forbidWords' => ((isset($_POST[$input_name . '_input_forbidden_words']) and $_POST[$input_name . '_input_forbidden_words'] != null) ? trim(ilUtil::secureString($_POST[$input_name . '_input_forbidden_words'])) : ''),
				'allowWords' => ((isset($_POST[$input_name . '_input_allow_words']) and $_POST[$input_name . '_input_allow_words'] != null) ? trim(ilUtil::secureString($_POST[$input_name . '_input_allow_words'])) : ''),
				'forbidFloats' => ((isset($_POST[$input_name . '_input_forbid_float']) and $_POST[$input_name . '_input_forbid_float'] != null) ? trim(ilUtil::secureString($_POST[$input_name . '_input_forbid_float'])) : ''),
				'lowestTerms' => ((isset($_POST[$input_name . '_input_require_lowest_terms']) and $_POST[$input_name . '_input_require_lowest_terms'] != null) ? (bool)trim(ilUtil::secureString($_POST[$input_name . '_input_require_lowest_terms'])) : ''),
				'sameType' => ((isset($_POST[$input_name . '_input_check_answer_type']) and $_POST[$input_name . '_input_check_answer_type'] != null) ? trim(ilUtil::secureString($_POST[$input_name . '_input_check_answer_type'])) : ''),
				'mustVerify' => ((isset($_POST[$input_name . '_input_must_verify']) and $_POST[$input_name . '_input_must_verify'] != null) ? trim(ilUtil::secureString($_POST[$input_name . '_input_must_verify'])) : ''),
				'showValidation' => ((isset($_POST[$input_name . '_input_show_validation']) and $_POST[$input_name . '_input_show_validation'] != null) ? trim(ilUtil::secureString($_POST[$input_name . '_input_show_validation'])) : ''),
				'options' => ((isset($_POST[$input_name . '_input_options']) and $_POST[$input_name . '_input_options'] != null) ? trim(ilUtil::secureString($_POST[$input_name . '_input_options'])) : ''),
			);

			$teacher_answer = ilUtil::secureString($_POST[$input_name . '_input_model_answer']);

			$parameters = array();
			foreach ($required_parameters[$type] as $parameter_name) {
				if ($parameter_name == 'inputType') {
					continue;
				}
				$parameters[$parameter_name] = $all_parameters[$parameter_name];
			}

			//SET INPUTS
			$this->object->inputs[$input_name] = stack_input_factory::make($type, $input_name, $teacher_answer, $this->object->options, $parameters);
		}

		//stack_potentialresponse_tree
		//Values
		$total_value = 0;

		//in ILIAS all attempts are graded
		$grade_all = true;

		$prt_from_post_array = array();


		//Load only those prt located in the question text or in the specific feedback.
		$prt_placeholders = stack_utils::extract_placeholders($this->object->getQuestion() . $this->object->specific_feedback, 'feedback');
		foreach ($prt_placeholders as $prt_name) {

			//Is new? Then load Standard PRT
			if (!isset($this->object->prts[$prt_name])) {
				$this->object->loadStandardPRT($prt_name);
				ilUtil::sendSuccess('New PRT: ' . $prt_name . ' Created', true);
			} else {

				//LOAD STORED DATA
				$prt_from_post_array[$prt_name]['value'] = ((isset($_POST['prt_' . $prt_name . '_value']) and $_POST['prt_' . $prt_name . '_value'] != null) ? trim(ilUtil::secureString($_POST['prt_' . $prt_name . '_value'])) : '');
				$prt_from_post_array[$prt_name]['auto_simplify'] = ((isset($_POST['prt_' . $prt_name . '_simplify']) and $_POST['prt_' . $prt_name . '_simplify'] != null) ? trim(ilUtil::secureString($_POST['prt_' . $prt_name . '_simplify'])) : '');
				$prt_from_post_array[$prt_name]['feedback_variables'] = ((isset($_POST['prt_' . $prt_name . '_feedback_variables']) and $_POST['prt_' . $prt_name . '_feedback_variables'] != null) ? assStackQuestionUtils::_debugText($_POST['prt_' . $prt_name . '_feedback_variables']) : '');
				$prt_from_post_array[$prt_name]['first_node_name'] = ((isset($_POST['prt_' . $prt_name . '_first_node']) and $_POST['prt_' . $prt_name . '_first_node'] != null) ? trim(ilUtil::secureString($_POST['prt_' . $prt_name . '_first_node'])) : '');

				//Look for node info
				foreach ($this->object->prts[$prt_name]->get_nodes_summary() as $node_id => $node) {

					$prefix = 'prt_' . $prt_name . '_node_' . $node_id;

					$prt_from_post_array[$prt_name]['nodes'][$node_id]['true_next_node'] = ((isset($_POST[$prefix . '_pos_next']) and $_POST[$prefix . '_pos_next'] != null) ? (int)trim(ilUtil::secureString($_POST[$prefix . '_pos_next'])) : -1);
					$prt_from_post_array[$prt_name]['nodes'][$node_id]['false_next_node'] = ((isset($_POST[$prefix . '_neg_next']) and $_POST[$prefix . '_neg_next'] != null) ? (int)trim(ilUtil::secureString($_POST[$prefix . '_neg_next'])) : -1);
					$prt_from_post_array[$prt_name]['nodes'][$node_id]['answer_test'] = ((isset($_POST[$prefix . '_answer_test']) and $_POST[$prefix . '_answer_test'] != null) ? trim(ilUtil::secureString($_POST[$prefix . '_answer_test'])) : '');
					$prt_from_post_array[$prt_name]['nodes'][$node_id]['sans'] = ((isset($_POST[$prefix . '_student_answer']) and $_POST[$prefix . '_student_answer'] != null) ? trim(ilUtil::secureString($_POST[$prefix . '_student_answer'])) : '');
					$prt_from_post_array[$prt_name]['nodes'][$node_id]['tans'] = ((isset($_POST[$prefix . '_teacher_answer']) and $_POST[$prefix . '_teacher_answer'] != null) ? trim(ilUtil::secureString($_POST[$prefix . '_teacher_answer'])) : '');
					$prt_from_post_array[$prt_name]['nodes'][$node_id]['test_options'] = ((isset($_POST[$prefix . '_options']) and $_POST[$prefix . '_options'] != null) ? trim(ilUtil::secureString($_POST[$prefix . '_options'])) : '');
					$prt_from_post_array[$prt_name]['nodes'][$node_id]['quiet'] = ((isset($_POST[$prefix . '_quiet']) and $_POST[$prefix . '_quiet'] != null) ? (int)trim(ilUtil::secureString($_POST[$prefix . '_quiet'])) : '');

					$prt_from_post_array[$prt_name]['nodes'][$node_id]['true_score'] = ((isset($_POST[$prefix . '_pos_score']) and $_POST[$prefix . '_pos_score'] != null) ? trim(ilUtil::secureString($_POST[$prefix . '_pos_score'])) : '');
					$prt_from_post_array[$prt_name]['nodes'][$node_id]['true_score_mode'] = ((isset($_POST[$prefix . '_pos_mod']) and $_POST[$prefix . '_pos_mod'] != null) ? trim(ilUtil::secureString($_POST[$prefix . '_pos_mod'])) : '');
					$prt_from_post_array[$prt_name]['nodes'][$node_id]['true_penalty'] = ((isset($_POST[$prefix . '_pos_penalty']) and $_POST[$prefix . '_pos_penalty'] != null) ? trim(ilUtil::secureString($_POST[$prefix . '_pos_penalty'])) : '');
					$prt_from_post_array[$prt_name]['nodes'][$node_id]['true_answer_note'] = ((isset($_POST[$prefix . '_pos_answernote']) and $_POST[$prefix . '_pos_answernote'] != null) ? trim(ilUtil::secureString($_POST[$prefix . '_pos_answernote'])) : '');
					$prt_from_post_array[$prt_name]['nodes'][$node_id]['true_feedback'] = ((isset($_POST[$prefix . '_pos_specific_feedback']) and $_POST[$prefix . '_pos_specific_feedback'] != null) ? ilRTE::_replaceMediaObjectImageSrc(trim(ilUtil::secureString($_POST[$prefix . '_pos_specific_feedback'], false))) : '');
					$prt_from_post_array[$prt_name]['nodes'][$node_id]['true_feedback_format'] = ((isset($_POST[$prefix . '_pos_feedback_class']) and $_POST[$prefix . '_pos_feedback_class'] != null) ? (int)trim(ilUtil::secureString($_POST[$prefix . '_pos_feedback_class'])) : '');

					$prt_from_post_array[$prt_name]['nodes'][$node_id]['false_score'] = ((isset($_POST[$prefix . '_neg_score']) and $_POST[$prefix . '_neg_score'] != null) ? trim(ilUtil::secureString($_POST[$prefix . '_neg_score'])) : '');
					$prt_from_post_array[$prt_name]['nodes'][$node_id]['false_score_mode'] = ((isset($_POST[$prefix . '_neg_mod']) and $_POST[$prefix . '_neg_mod'] != null) ? trim(ilUtil::secureString($_POST[$prefix . '_neg_mod'])) : '');
					$prt_from_post_array[$prt_name]['nodes'][$node_id]['false_penalty'] = ((isset($_POST[$prefix . '_neg_penalty']) and $_POST[$prefix . '_neg_penalty'] != null) ? trim(ilUtil::secureString($_POST[$prefix . '_neg_penalty'])) : '');
					$prt_from_post_array[$prt_name]['nodes'][$node_id]['false_answer_note'] = ((isset($_POST[$prefix . '_neg_answernote']) and $_POST[$prefix . '_neg_answernote'] != null) ? trim(ilUtil::secureString($_POST[$prefix . '_neg_answernote'])) : '');
					$prt_from_post_array[$prt_name]['nodes'][$node_id]['false_feedback'] = ((isset($_POST[$prefix . '_neg_specific_feedback']) and $_POST[$prefix . '_neg_specific_feedback'] != null) ? ilRTE::_replaceMediaObjectImageSrc(trim(ilUtil::secureString($_POST[$prefix . '_neg_specific_feedback'], false))) : '');
					$prt_from_post_array[$prt_name]['nodes'][$node_id]['false_feedback_format'] = ((isset($_POST[$prefix . '_neg_feedback_class']) and $_POST[$prefix . '_neg_feedback_class'] != null) ? (int)trim(ilUtil::secureString($_POST[$prefix . '_neg_feedback_class'])) : '');

				}

				$prt_data = $prt_from_post_array[$prt_name];
				$nodes = array();

				foreach ($prt_data['nodes'] as $node_name => $node_data) {

					$sans = stack_ast_container::make_from_teacher_source('PRSANS' . $node_name . ':' . $node_data['sans'], '', new stack_cas_security());
					$tans = stack_ast_container::make_from_teacher_source('PRTANS' . $node_name . ':' . $node_data['tans'], '', new stack_cas_security());

					//Penalties management, penalties are not an ILIAS Feature
					if (is_null($node_data['false_penalty']) || $node_data['false_penalty'] === '') {
						$false_penalty = 0;
					} else {
						$false_penalty = $node_data['false_penalty'];
					}

					if (is_null(($node_data['true_penalty']) || $node_data['true_penalty'] === '')) {
						$true_penalty = 0;
					} else {
						$true_penalty = $node_data['true_penalty'];
					}

					try {
						//Create Node and add it to the

						$node = new stack_potentialresponse_node($sans, $tans, $node_data['answer_test'], $node_data['test_options'], (bool)$node_data['quiet'], '', (int)$node_name, $node_data['sans'], $node_data['tans']);

						$node->add_branch(0, $node_data['false_score_mode'], $node_data['false_score'], $false_penalty, $node_data['false_next_node'], ilUtil::stripSlashes($node_data['false_feedback'], true, $this->getRTETags()), $node_data['false_feedback_format'], $node_data['false_answer_note']);
						$node->add_branch(1, $node_data['true_score_mode'], $node_data['true_score'], $true_penalty, $node_data['true_next_node'], ilUtil::stripSlashes($node_data['true_feedback'], true, $this->getRTETags()), $node_data['true_feedback_format'], $node_data['true_answer_note']);

						$nodes[$node_name] = $node;
					} catch (stack_exception $e) {
						ilUtil::sendFailure($e, true);
					}
				}

				if ($prt_data['feedback_variables']) {
					try {
						$feedback_variables = new stack_cas_keyval($prt_data['feedback_variables']);
						$feedback_variables = $feedback_variables->get_session();
					} catch (stack_exception $e) {
						ilUtil::sendFailure($e, true);
					}
				} else {
					$feedback_variables = null;
				}

				foreach ($prt_from_post_array as $prt_name => $prt_data) {
					$total_value += $prt_data['value'];
				}

				if ($prt_from_post_array && $grade_all && $total_value < 0.0000001) {
					try {
						throw new stack_exception('There is an error authoring your question. ' .
							'The $totalvalue, the marks available for the question, must be positive in question ' .
							$this->object->getTitle());
					} catch (stack_exception $e) {
						ilUtil::sendFailure($e, true);
					}
				}

				$prt_value = $prt_data['value'];

				try {
					$this->object->prts[$prt_name] = new stack_potentialresponse_tree($prt_name, '', (bool)$prt_data['auto_simplify'], $prt_value, $feedback_variables, $nodes, (string)$prt_data['first_node_name'], 1);
				} catch (stack_exception $e) {
					ilUtil::sendFailure($e, true);
				}
			}
		}
	}

	/**
	 * Populate taxonomy section in a form
	 * (made public to be called from authoring GUI)
	 *
	 * @param ilPropertyFormGUI $form
	 */
	public function populateTaxonomyFormSection(ilPropertyFormGUI $form):void
	{
		parent::populateTaxonomyFormSection($form);
	}

	/**
	 * Returns the answer generic feedback depending on the results of the question
	 *
	 * @param integer $active_id Active ID of the user
	 * @param integer $pass Active pass
	 * @return string HTML Code with the answer specific feedback
	 * @access public
	 * @deprecated Use getGenericFeedbackOutput instead.
	 */
	function getAnswerFeedbackOutput($active_id, $pass): string
	{
		return $this->getGenericFeedbackOutput($active_id, $pass);
	}

	/* ILIAS OVERWRITTEN METHODS END */

	/* ILIAS GUI COMMANDS METHODS BEGIN */

	/**
	 * Creates an output of the edit form for the question
	 *
	 * @param bool $check_only
	 *
	 */
	public function editQuestion(bool $check_only = false)
	{
		$save = $this->isSaveCommand();

		global $DIC;

		//Tabs management
		//TODO Aware on the Learning Modules tab if $this->object->getSelfAssessmentEditingMode() is active
		$tabs = $DIC->tabs();
		$tabs->activateTab('edit_properties');
		$tabs->activateSubTab('edit_question');

		//TODO Is working still in ILIAS7? see comments
		$this->getQuestionTemplate();

		//Create GUI object
		$this->plugin->includeClass('GUI/question_authoring/class.assStackQuestionAuthoringGUI.php');
		$authoring_gui = new assStackQuestionAuthoringGUI($this->plugin, $this);

		//Add CSS
		$DIC->globalScreen()->layout()->meta()->addCss($this->plugin->getStyleSheetLocation('css/qpl_xqcas_authoring.css'));
		$DIC->globalScreen()->layout()->meta()->addCss($this->plugin->getStyleSheetLocation('css/multipart_form.css'));

		//Javascript

		//Show info messages
		$this->info_config = new stdClass();
		$ctrl = $DIC->ctrl();
		$this->info_config->ajax_url = $ctrl->getLinkTargetByClass("assstackquestiongui", "saveInfoState", "", TRUE);

		//Set to user's session value
		if (isset($_SESSION['stack_authoring_show'])) {
			$this->info_config->show = (int)$_SESSION['stack_authoring_show'];
		} else {
			//first time must be shown
			$this->info_config->show = 1;
		}
		$DIC->globalScreen()->layout()->meta()->addJs('Customizing/global/plugins/Modules/TestQuestionPool/Questions/assStackQuestion/templates/js/ilEnableDisableInfo.js');
		$DIC->globalScreen()->layout()->meta()->addOnLoadCode('il.EnableDisableInfo.initInfoMessages(' . json_encode($this->info_config) . ')');

		//Reform authoring interface
		$DIC->globalScreen()->layout()->meta()->addJs('Customizing/global/plugins/Modules/TestQuestionPool/Questions/assStackQuestion/templates/js/ilMultipartFormProperty.js');

        //35855 ensure warning if shown if no question note is added when randomised
        if(assStackQuestionUtils::_showRandomisationWarning($this->object)){
            ilUtil::sendInfo(stack_string('questionnotempty'));
        }

		//Returns Question Authoring form
		if (!$check_only) {
			$this->tpl->setVariable("QUESTION_DATA", $authoring_gui->showAuthoringPanel());
		}
	}


	/**
	 * Called by writePostData
	 * handles the copy/paste of PRT/nodes.
	 * Access the DB
	 * TODO
	 */
	public function questionCheck(): bool
	{

		global $DIC;
		$lng = $DIC->language();

		if (is_array($_POST['cmd']['save'])) {

			//PRT OPERATIONS
			foreach ($this->object->prts as $prt_name => $prt) {

				//PRT Copy
				if (isset($_POST['cmd']['save']['copy_prt_' . $prt_name])) {
					//Set prt name and question id into session
					$_SESSION['copy_prt'] = $this->object->getId() . "_" . $prt_name;

					ilUtil::sendInfo($lng->txt("qpl_qst_xqcas_prt_copied_to_clipboard"), true);
					return true;
				}

				//PRT Paste
				if (isset($_POST['cmd']['save']['paste_prt'])) {

					$raw_data = explode("_", $_SESSION['copy_prt']);
					$original_question_id = $raw_data[0];
					$original_prt_name = $raw_data[1];

					//Generate the new prt name,
					$generated_prt_name = "prt" . (string)rand(20, 1000);

					if (assStackQuestionDB::_copyPRTFunction($original_question_id, $original_prt_name, $this->object->getId(), $generated_prt_name)) {

						//Include placeholder in specific feedback
						$current_specific_feedback = $this->object->specific_feedback;
						$new_specific_feedback = "<p>" . $current_specific_feedback . "[[feedback:" . $generated_prt_name . "]]</p>";
						$_POST["options_specific_feedback"] = $new_specific_feedback;

						return true;
					} else {
						return false;
					}

				}

				//NODE OPERATIONS
				foreach ($prt->getNodes() as $node_name => $node) {

					//Add Node
					if (isset($_POST['cmd']['save']['add_node_to_' . $prt_name])) {

						//Check the new node name,
						//We set as id the following to the current bigger node id
						$max = 0;
						foreach ($prt->getNodes() as $temp_node_name => $temp_node) {
							(int)$temp_node_name > $max ? $max = (int)$temp_node_name : "";
						}
						$new_node_name = $max + 1;

						if (assStackQuestionDB::_addNodeFunction($this->object->getId(), $prt_name, $new_node_name)) {
							return true;
						} else {
							return false;
						}
					}

					//Delete node
					if (isset($_POST['cmd']['save']['delete_prt_' . $prt_name . '_node_' . $node->nodeid])) {

						if (sizeof($prt->getNodes()) < 2) {
							ilUtil::sendFailure($this->object->getPlugin()->txt('deletion_error_not_enought_prt_nodes'));
							return false;
						}

						if ((int)$prt->getFirstNode() == (int)$node_name) {
							ilUtil::sendFailure($this->object->getPlugin()->txt('deletion_error_first_node'));
							return false;
						}

						assStackQuestionDB::_deleteStackPrtNodes($this->object->getId(), $prt_name, $node->nodeid);

						//Actualize current question values
						$new_nodes = $prt->getNodes();
						unset($new_nodes[$node_name]);

						$prt->setNodes($new_nodes);
						$this->object->prts[$prt_name] = $prt;

						ilUtil::sendSuccess("nodes deleted", true);
						return true;
					}

					//Copy Node
					if (isset($_POST['cmd']['save']['copy_prt_' . $prt_name . '_node_' . $node->nodeid])) {
						//Set node into session
						$_SESSION['copy_node'] = $this->object->getId() . "_" . $prt_name . "_" . $node->nodeid;

						ilUtil::sendInfo($lng->txt("qpl_qst_xqcas_node_copied_to_clipboard"), true);
						return true;
					}

					//Paste Node
					if (isset($_POST['cmd']['save']['paste_node_in_' . $prt_name])) {

						//Do node paste here
						$raw_data = explode("_", $_SESSION['copy_node']);
						$original_question_id = $raw_data[0];
						$original_prt_name = $raw_data[1];
						$original_node_name = $raw_data[2];

						//Check the new node name,
						//We set as id the following to the current bigger node id
						$max = 0;
						foreach ($prt->getNodes() as $temp_node_name => $temp_node) {
							(int)$temp_node_name > $max ? $max = (int)$temp_node_name : "";
						}
						$new_node_name = $max + 1;

						if (assStackQuestionDB::_copyNodeFunction($original_question_id, $original_prt_name, $original_node_name, $this->object->getId(), $prt_name, $new_node_name)) {
							return true;
						} else {
							return false;
						}
					}
				}

			}

		}

		return false;
	}



	/* RTE, Javascript, Ajax, jQuery etc. METHODS BEGIN */

	/**
	 * Decides whether to show the information fields in the session
	 * Called by editQuestion onLoad
	 */
	public function enableDisableInfo()
	{

		if (isset($_SESSION['show_input_info_fields_in_form'])) {
			if ($_SESSION['show_input_info_fields_in_form'] == TRUE) {
				$_SESSION['show_input_info_fields_in_form'] = FALSE;
			} else {
				$_SESSION['show_input_info_fields_in_form'] = TRUE;
			}
		} else {
			$_SESSION['show_input_info_fields_in_form'] = TRUE;
		}

		//Redirects to show Question Form
		$this->editQuestion();
	}

	/**
	 * Save the showing info messages state in the user session
	 * (This keeps info messages state between page moves)
	 * @see self::addToPage()
	 */
	public function saveInfoState()
	{
		$_SESSION['stack_authoring_show'] = (int)$_GET['show'];

		// debugging output (normally ignored by the js part)
		echo json_encode(array('show' => $_SESSION['stack_authoring_show']));
		exit;
	}

	/**
	 * Init the STACK specific rich text editing support
	 * The allowed html tags are stored in an own settings module instead of "assessment"
	 * This enabled an independent tag set from the editor settings in ILIAS administration
	 * Text area fields will be initialized with SetRTESupport using this module
	 */
	public function initRTESupport()
	{
		include_once "./Services/AdvancedEditing/classes/class.ilObjAdvancedEditing.php";
		$this->rte_tags = ilObjAdvancedEditing::_getUsedHTMLTags($this->rte_module);

		$this->required_tags = array("a", "blockquote", "br", "cite", "code", "div", "em", "h1", "h2", "h3", "h4", "h5", "h6", "hr", "img", "li", "ol", "p", "pre", "span", "strike", "strong", "sub", "sup", "table", "caption", "thead", "th", "td", "tr", "u", "ul", "i", "b", "gap");

		if (serialize($this->rte_tags) != serialize(($this->required_tags))) {

			$this->rte_tags = $this->required_tags;
			$obj_advance = new ilObjAdvancedEditing();
			$obj_advance->setUsedHTMLTags($this->rte_tags, $this->rte_module);
		}
	}


	/**
	 * Set the STACK specific rich text editing support in textarea fields
	 * This uses an own module instead of "assessment" to determine the allowed tags
	 */
	public function setRTESupport(ilTextAreaInputGUI $field)
	{
		if (empty($this->rte_tags)) {
			$this->initRTESupport();
		}
		$field->setUseRte(true);
		$field->setRteTags($this->rte_tags);
		$field->addPlugin("latex");
		$field->addButton("latex");
		$field->addButton("pastelatex");
		$field->setRTESupport($this->object->getId(), "qpl", $this->rte_module);
	}

	/**
	 * Sets the ILIAS tabs for this question type
	 * called from ilObjTestGUI and ilObjQuestionPoolGUI
	 */
	public function setQuestionTabs():void
	{
		global $DIC, $rbacsystem;

		$tabs = $DIC->tabs();

		$this->ctrl->setParameterByClass("ilAssQuestionPageGUI", "q_id", $_GET["q_id"]);
		include_once "./Modules/TestQuestionPool/classes/class.assQuestion.php";
		$this->plugin->includeClass('class.ilAssStackQuestionFeedback.php');

		$q_type = $this->object->getQuestionType();

		if (strlen($q_type)) {
			$classname = $q_type . "GUI";
			$this->ctrl->setParameterByClass(strtolower($classname), "sel_question_types", $q_type);
			$this->ctrl->setParameterByClass(strtolower($classname), "q_id", $_GET["q_id"]);
		}

		if ($_GET["q_id"]) {
			if ($rbacsystem->checkAccess('write', $_GET["ref_id"])) {
				// edit page
				$tabs->addTarget("edit_page", $this->ctrl->getLinkTargetByClass("ilAssQuestionPageGUI", "edit"), array("edit", "insert", "exec_pg"), "", "", "");
			}

			// edit page
			$tabs->addTarget("preview", $this->ctrl->getLinkTargetByClass("ilAssQuestionPreviewGUI", "show"), array("preview"), "ilAssQuestionPageGUI", "", "");
		}

		$force_active = false;
		if ($rbacsystem->checkAccess('write', $_GET["ref_id"])) {
			$url = "";

			if ($classname) {
				$url = $this->ctrl->getLinkTargetByClass($classname, "editQuestion");
			}
			$commands = $_POST["cmd"];
			if (is_array($commands)) {
				foreach ($commands as $key => $value) {
					if (preg_match("/^suggestrange_.*/", $key, $matches)) {
						$force_active = true;
					}
				}
			}
			// edit question properties
			$tabs->addTarget("edit_properties", $url, array("editQuestion", "save", "cancel", "addSuggestedSolution", "cancelExplorer", "linkChilds", "removeSuggestedSolution", "parseQuestion", "saveEdit", "suggestRange"), $classname, "", $force_active);

			$this->addTab_QuestionFeedback($tabs);

			if (in_array($_GET['cmd'], array('importQuestionFromMoodleForm', 'importQuestionFromMoodle', 'editQuestion', 'scoringManagement', 'scoringManagementPanel', 'deployedSeedsManagement', 'createNewDeployedSeed', 'deleteDeployedSeed', 'showUnitTests', 'runTestcases', 'createTestcases', 'post', 'exportQuestiontoMoodleForm', 'exportQuestionToMoodle',))) {
				$tabs->addSubTab('edit_question', $this->plugin->txt('edit_question'), $this->ctrl->getLinkTargetByClass($classname, "editQuestion"));
				$tabs->addSubTab('scoring_management', $this->plugin->txt('scoring_management'), $this->ctrl->getLinkTargetByClass($classname, "scoringManagementPanel"));
				$tabs->addSubTab('deployed_seeds_management', $this->plugin->txt('dsm_deployed_seeds'), $this->ctrl->getLinkTargetByClass($classname, "deployedSeedsManagement"));
				//$tabs->addSubTab('unit_tests', $this->plugin->txt('ut_title'), $this->ctrl->getLinkTargetByClass($classname, "showUnitTests"));
				$tabs->addSubTab('import_from_moodle', $this->plugin->txt('import_from_moodle'), $this->ctrl->getLinkTargetByClass($classname, "importQuestionFromMoodleForm"));
				$tabs->addSubTab('export_to_moodle', $this->plugin->txt('export_to_moodle'), $this->ctrl->getLinkTargetByClass($classname, "exportQuestiontoMoodleForm"));
			}

		}

		// Assessment of questions sub menu entry
		if ($_GET["q_id"]) {
			$tabs->addTarget("statistics", $this->ctrl->getLinkTargetByClass($classname, "assessment"), array("assessment"), $classname, "");
		}

		if (($_GET["calling_test"] > 0) || ($_GET["test_ref_id"] > 0)) {
			$ref_id = $_GET["calling_test"];
			if (strlen($ref_id) == 0) {
				$ref_id = $_GET["test_ref_id"];
			}
			$tabs->setBackTarget($this->lng->txt("backtocallingtest"), "ilias.php?baseClass=ilObjTestGUI&cmd=questions&ref_id=$ref_id");
		} else {
			$tabs->setBackTarget($this->lng->txt("qpl"), $this->ctrl->getLinkTargetByClass("ilobjquestionpoolgui", "questions"));
		}

	}

	/**
	 * For Learning Module Rendering
	 * @return void
	 */
	public function getLearningModuleTabs()
	{
		global $DIC;
		$tabs = $DIC->tabs();

		$this->ctrl->setParameterByClass("ilAssQuestionPageGUI", "q_id", $_GET["q_id"]);
		include_once "./Modules/TestQuestionPool/classes/class.assQuestion.php";
		$this->plugin->includeClass('class.ilAssStackQuestionFeedback.php');

		$q_type = $this->object->getQuestionType();

		if (strlen($q_type)) {
			$classname = $q_type . "GUI";
			$this->ctrl->setParameterByClass(strtolower($classname), "sel_question_types", $q_type);
			$this->ctrl->setParameterByClass(strtolower($classname), "q_id", $this->object->getId());
		}

		$force_active = false;
		$url = "";

		if ($classname) {
			$url = $this->ctrl->getLinkTargetByClass($classname, "editQuestion");
		}
		$commands = $_POST["cmd"];
		if (is_array($commands)) {
			foreach ($commands as $key => $value) {
				if (preg_match("/^suggestrange_.*/", $key, $matches)) {
					$force_active = true;
				}
			}
		}
		// edit question properties
		$tabs->addTarget("edit_properties", $url, array("editQuestion", "save", "cancel", "addSuggestedSolution", "cancelExplorer", "linkChilds", "removeSuggestedSolution", "parseQuestion", "saveEdit", "suggestRange"), $classname, "", $force_active);

		if (in_array($_GET['cmd'], array('importQuestionFromMoodleForm', 'importQuestionFromMoodle', 'editQuestion', 'scoringManagement', 'scoringManagementPanel', 'deployedSeedsManagement', 'createNewDeployedSeed', 'deleteDeployedSeed', 'showUnitTests', 'runTestcases', 'createTestcases', 'post', 'exportQuestiontoMoodleForm', 'exportQuestionToMoodle',))) {
			$tabs->addSubTab('edit_question', $this->plugin->txt('edit_question'), $this->ctrl->getLinkTargetByClass($classname, "editQuestion"));
			$tabs->addSubTab('scoring_management', $this->plugin->txt('scoring_management'), $this->ctrl->getLinkTargetByClass($classname, "scoringManagementPanel"));
			$tabs->addSubTab('deployed_seeds_management', $this->plugin->txt('dsm_deployed_seeds'), $this->ctrl->getLinkTargetByClass($classname, "deployedSeedsManagement"));
			$tabs->addSubTab('unit_tests', $this->plugin->txt('ut_title'), $this->ctrl->getLinkTargetByClass($classname, "showUnitTests"));
		}

	}

	/**
	 * Redirects to the import from MoodleXML Form
	 * @return void
	 */
	public function importQuestionFromMoodleForm()
	{
		global $DIC;

		$lng = $DIC->language();
		$tabs = $DIC->tabs();

		//#25145
		if (isset($_REQUEST["test_ref_id"])) {
			ilUtil::sendFailure($lng->txt("qpl_qst_xqcas_import_in_test_error"), TRUE);
			$DIC->ctrl()->redirect($this, 'editQuestion');
		}

		if ($this->object->getSelfAssessmentEditingMode()) {
			$this->getLearningModuleTabs();
		}
		//Set all parameters required
		$tabs->activateTab('edit_properties');
		$tabs->activateSubTab('import_from_moodle');

		require_once("./Services/Form/classes/class.ilPropertyFormGUI.php");

		$form = new ilPropertyFormGUI();
		$form->setFormAction($this->ctrl->getFormAction($this));
		$form->setTitle($lng->txt("qpl_qst_xqcas_import_xml"));

		//Upload XML file
		$item = new ilFileInputGUI($lng->txt("qpl_qst_xqcas_import_xml_file"), 'questions_xml');
		$item->setSuffixes(array('xml'));
		$form->addItem($item);

		$hiddenFirstId = new ilHiddenInputGUI('first_question_id');
		$hiddenFirstId->setValue($_GET['q_id']);
		$form->addItem($hiddenFirstId);

		$form->addCommandButton("importQuestionFromMoodle", $lng->txt("import"));
		$form->addCommandButton("editQuestion", $lng->txt("cancel"));

		$this->tpl->setContent($form->getHTML());
	}

	/**
	 * Actually runs the Importing of questions
	 * @return void
	 */
	public function importQuestionFromMoodle()
	{
		global $DIC;
		$tabs = $DIC->tabs();

		//Set all parameters required
		$tabs->activateTab('edit_properties');
		$tabs->activateSubTab('import_from_moodle');

		//Getting the xml file from $_FILES
		if (file_exists($_FILES["questions_xml"]["tmp_name"])) {
			$xml_file = $_FILES["questions_xml"]["tmp_name"];
		} else {
			ilUtil::sendFailure($this->plugin->txt('error_import_question_in_test'), true);
			return;
		}

		//CHECK FOR NOT ALLOW IMPROT QUESTIONS DIRECTLY IN TESTS
		if (isset($_GET['calling_test'])) {
			ilUtil::sendFailure($this->plugin->txt('error_import_question_in_test'), true);
		} else {
			//Include import class and prepare object
			$this->plugin->includeClass('model/import/MoodleXML/class.assStackQuestionMoodleImport.php');
			$import = new assStackQuestionMoodleImport($this->plugin, (int)$_POST['first_question_id'], $this->object);
			$import->setRTETags($this->getRTETags());
			$import->import($xml_file);

			$DIC->ctrl()->redirect($this, 'editQuestion');
		}
	}

	/**
	 * Redirects to the export from MoodleXML Form
	 * @return void
	 */
	public function exportQuestiontoMoodleForm()
	{
		global $DIC;
		$tabs = $DIC->tabs();
		$lng = $DIC->language();

		//Set all parameters required
		$tabs->activateTab('edit_properties');
		$tabs->activateSubTab('export_to_moodle');

		require_once("./Services/Form/classes/class.ilPropertyFormGUI.php");

		$form = new ilPropertyFormGUI();
		$form->setFormAction($this->ctrl->getFormAction($this));
		$form->setTitle($lng->txt("qpl_qst_xqcas_export_to_moodlexml"));

		$options = new ilRadioGroupInputGUI($lng->txt("qpl_qst_xqcas_all_from_pool"), "xqcas_all_from_pool");
		$only_question = new ilRadioOption($lng->txt("qpl_qst_xqcas_export_only_this"), "xqcas_export_only_this", $lng->txt("qpl_qst_xqcas_export_only_this_info"));
		if (isset($_GET['calling_test'])) {
			$all_from_pool = new ilRadioOption($lng->txt("qpl_qst_xqcas_export_all_from_test"), "xqcas_export_all_from_test", $lng->txt("qpl_qst_xqcas_export_all_from_test_info"));
		} else {
			$all_from_pool = new ilRadioOption($lng->txt("qpl_qst_xqcas_export_all_from_pool"), "xqcas_export_all_from_pool", $lng->txt("qpl_qst_xqcas_export_all_from_pool_info"));
		}

		$options->addOption($only_question);
		$options->addOption($all_from_pool);

		if (isset($_GET['calling_test'])) {
			$options->setValue("xqcas_export_all_from_test");
		} else {
			$options->setValue("xqcas_export_all_from_pool");
		}

		$form->addItem($options);

		$hiddenFirstId = new ilHiddenInputGUI('first_question_id');
		$hiddenFirstId->setValue($_GET['q_id']);
		$form->addItem($hiddenFirstId);

		$form->addCommandButton("exportQuestionToMoodle", $lng->txt("export"));
		$form->addCommandButton("editQuestion", $lng->txt("cancel"));

		$this->tpl->setContent($form->getHTML());
	}

	/**
	 * Actually runs the export to MoodleXML
	 * @return void
	 */
	public function exportQuestionToMoodle()
	{
		global $DIC;
		$tabs = $DIC->tabs();
		$lng = $DIC->language();

		require_once './Customizing/global/plugins/Modules/TestQuestionPool/Questions/assStackQuestion/classes/export/MoodleXML/class.assStackQuestionMoodleXMLExport.php';

		//Set all parameters required
		$tabs->activateTab('edit_properties');
		$tabs->activateSubTab('export_to_moodle');

		//Getting data from POST
		if (isset($_POST['first_question_id']) and isset($_POST['xqcas_all_from_pool'])) {
			$question_id = (int)$_POST['first_question_id'];
			$q_type_id = $this->object->getQuestionTypeID();
			try {
				if ($_POST['xqcas_all_from_pool'] == 'xqcas_export_all_from_pool') {
					//Get all questions from a pool
					$questions = assStackQuestionDB::_getAllQuestionsFromPool($question_id, $q_type_id);
					$export_to_moodle = new assStackQuestionMoodleXMLExport($questions);
				} elseif ($_POST['xqcas_all_from_pool'] == 'xqcas_export_only_this') {
					//get current stack question info.
					$export_to_moodle = new assStackQuestionMoodleXMLExport(array($question_id => $this->object));
				} elseif ($_POST['xqcas_all_from_pool'] == 'xqcas_export_all_from_test') {
					//get current stack question info.
					$questions = assStackQuestionDB::_getAllQuestionsFromTest($question_id, $q_type_id);
					$export_to_moodle = new assStackQuestionMoodleXMLExport($questions);
				}

				$export_to_moodle->toMoodleXML();

			} catch (stack_exception $e) {
				ilUtil::sendFailure($e, true);
			}

		} else {
			ilUtil::sendFailure($lng->txt('qpl_qst_xqcas_error_exporting_to_moodle_question_id'), true);
		}
	}


	/**
	 * Redirects to the Deployed Seeds Tabs
	 * @return void
	 */
	public function deployedSeedsManagement()
	{
		global $DIC;
		$tabs = $DIC->tabs();

		if ($this->object->getSelfAssessmentEditingMode()) {
			$this->getLearningModuleTabs();
		}
		//Set all parameters required
		$tabs->activateTab('edit_properties');
		$tabs->activateSubTab('deployed_seeds_management');
		$this->getQuestionTemplate();

		//Create GUI object
		$this->getPlugin()->includeClass('GUI/question_authoring/class.assStackQuestionDeployedSeedsGUI.php');
		$deployed_seeds_gui = new assStackQuestionDeployedSeedsGUI($this->plugin, $this->object->getId(), $this);

		//Add MathJax (Ensure MathJax is loaded)
		include_once "./Services/Administration/classes/class.ilSetting.php";
		$mathJaxSetting = new ilSetting("MathJax");
		$DIC->globalScreen()->layout()->meta()->addJs($mathJaxSetting->get("path_to_mathjax"));

		//Add CSS
		$DIC->globalScreen()->layout()->meta()->addCss($this->plugin->getStyleSheetLocation('css/qpl_xqcas_deployed_seeds_management.css'));

		//Returns Deployed seeds form
		$this->tpl->setVariable("QUESTION_DATA", $deployed_seeds_gui->showDeployedSeedsPanel());
	}

	/**
	 * Deploys new seed in the Deployed Seeds Tabs
	 * @return void
	 */
	public function createNewDeployedSeed()
	{
		global $DIC;
		$tabs = $DIC->tabs();
		//Set all parameters required
		$tabs->activateTab('edit_properties');
		$tabs->activateSubTab('deployed_seeds_management');
		$this->getQuestionTemplate();

		//New seed creation
		$seed = (int)$_POST['deployed_seed'];
		$question_id = (int)$_POST['question_id'];

        //save seed
        assStackQuestionDB::_saveStackSeeds($this->object,'add',$seed);

		$this->deployedSeedsManagement();
	}

	/*
	 * Deletes a deployed seed
	 */
	public function deleteDeployedSeed()
	{
		global $DIC;
		$tabs = $DIC->tabs();
		//Set all parameters required
		$tabs->activateTab('edit_properties');
		$tabs->activateSubTab('deployed_seeds_management');
		$this->getQuestionTemplate();

		//New seed creation
		$seed = (int)$_POST['deployed_seed'];
		$question_id = (int)$_POST['question_id'];

        //delete seed
        assStackQuestionDB::_deleteStackSeeds($question_id,'',$seed);


		$this->deployedSeedsManagement();
	}

	/**
	 * This function is called when scoring tab is activated.
	 * Shows the evaluation structure of the question by potentialresponse tree and a simulation
	 * of the value of each PRT in real points, in order to change it.
	 * @param float $new_question_points
	 */
	public function scoringManagementPanel($new_question_points = '')
	{
		global $DIC;
		$tabs = $DIC->tabs();
		if ($this->object->getSelfAssessmentEditingMode()) {
			$this->getLearningModuleTabs();
		}
		//Set all parameters required
		$tabs->activateTab('edit_properties');
		$tabs->activateSubTab('scoring_management');
		$this->getQuestionTemplate();

		//Create GUI object
		$this->plugin->includeClass('GUI/question_authoring/class.assStackQuestionScoringGUI.php');
		$scoring_gui = new assStackQuestionScoringGUI($this->plugin, $this->object, $this->object->getPoints());

		//Add CSS
		$DIC->globalScreen()->layout()->meta()->addCss($this->plugin->getStyleSheetLocation('css/qpl_xqcas_scoring_management.css'));

		//Returns Deployed seeds form
		$this->tpl->setVariable("QUESTION_DATA", $scoring_gui->showScoringPanel($new_question_points));
	}

	/**
	 * This command is called when user requires a comparison between current evaluation
	 * structure and a new one with the point value he insert in the input field.
	 */
	public function showScoringComparison()
	{
		//Get new points value
		if (isset($_POST['new_scoring']) and (float)$_POST['new_scoring'] > 0.0) {
			$new_question_points = (float)ilUtil::stripSlashes($_POST['new_scoring']);
		} else {
			$this->question_gui->object->setErrors($this->plugin->txt('sco_invalid_value'));
		}
		//Show scoring panel with comparison
		$this->scoringManagementPanel($new_question_points);
	}

	/**
	 * This command is called when the user wants to change the points value of the
	 * question to the value inserted in the input field.
	 */
	public function saveNewScoring()
	{
		//Get new points value and save it to the DB
		if (isset($_POST['new_scoring']) and (float)$_POST['new_scoring'] > 0.0) {
			$this->object->setPoints(ilUtil::stripSlashes($_POST['new_scoring']));
			$this->object->saveQuestionDataToDb($this->object->getId());
		} else {
			$this->question_gui->object->setErrors($this->plugin->txt('sco_invalid_value'));
		}
		//Show scoring panel
		$this->scoringManagementPanel();
	}


	/**
	 * Command for run testcases
	 */
	public function runTestcases()
	{
		global $DIC;
		$tabs = $DIC->tabs();

		//Set all parameters required
		$this->plugin->includeClass('utils/class.assStackQuestionStackFactory.php');
		$tabs->activateTab('edit_properties');
		$tabs->activateSubTab('unit_tests');
		$this->getQuestionTemplate();

		//get Post vars
		if (isset($_POST['test_id'])) {
			$test_id = $_POST['test_id'];
		}
		if (isset($_POST['question_id'])) {
			$question_id = $_POST['question_id'];
		}
		if (isset($_POST['testcase_name'])) {
			$testcase_name = $_POST['testcase_name'];
		} else {
			$testcase_name = FALSE;
		}

		//Create STACK Question object if doesn't exists
		if (!is_a($this->object->getStackQuestion(), 'assStackQuestionStackQuestion')) {
			$this->plugin->includeClass("model/class.assStackQuestionStackQuestion.php");
			$this->object->setStackQuestion(new assStackQuestionStackQuestion());
			$this->object->getStackQuestion()->init($this->object);
		}

		//Create Unit test object
		$this->plugin->includeClass("model/ilias_object/test/class.assStackQuestionUnitTests.php");
		$unit_tests_object = new assStackQuestionUnitTests($this->plugin, $this->object);
		$unit_test_results = $unit_tests_object->runTest($testcase_name);

		//Create GUI object
		$this->plugin->includeClass('GUI/test/class.assStackQuestionTestGUI.php');
		$unit_test_gui = new assStackQuestionTestGUI($this, $this->plugin, $unit_test_results);

		//Add CSS
		$DIC->globalScreen()->layout()->meta()->addCss($this->plugin->getStyleSheetLocation('css/qpl_xqcas_unit_tests.css'));

		//Returns Deployed seeds form
		$this->tpl->setVariable("QUESTION_DATA", $unit_test_gui->showUnitTestsPanel(TRUE));
	}

	/**
	 * Command for edit testcases
	 */
	public function editTestcases()
	{
		global $DIC;
		$tabs = $DIC->tabs();

		//Set all parameters required
		$this->plugin->includeClass('utils/class.assStackQuestionStackFactory.php');
		$tabs->activateTab('edit_properties');
		$tabs->activateSubTab('unit_tests');
		$this->getQuestionTemplate();

		//get Post vars
		if (isset($_POST['test_id'])) {
			$test_id = $_POST['test_id'];
		}
		if (isset($_POST['question_id'])) {
			$question_id = $_POST['question_id'];
		}
		if (isset($_POST['testcase_name'])) {
			$testcase_name = $_POST['testcase_name'];
		} else {
			$testcase_name = FALSE;
		}

		//Create unit test object
		$this->plugin->includeClass("model/ilias_object/test/class.assStackQuestionUnitTests.php");
		$unit_tests_object = new assStackQuestionUnitTests($this->plugin, $this->object);

		//Create GUI object
		$this->plugin->includeClass('GUI/test/class.assStackQuestionTestGUI.php');
		$unit_test_gui = new assStackQuestionTestGUI($this, $this->plugin);

		//Add CSS
		$DIC->globalScreen()->layout()->meta()->addCss($this->plugin->getStyleSheetLocation('css/qpl_xqcas_unit_tests.css'));

		//Returns Deployed seeds form
		$this->tpl->setVariable("QUESTION_DATA", $unit_test_gui->editTestcaseForm($testcase_name, $this->object->getInputs(), $this->object->getPotentialResponsesTrees()));
	}

	/**
	 * Calling command for edit testcases
	 */
	public function doEditTestcase()
	{
		if (isset($_POST['testcase_name'])) {
			$testcase_name = $_POST['testcase_name'];
			$test = $this->object->getTests($testcase_name);
		} else {
			$testcase_name = FALSE;
		}

		if (is_a($test, 'assStackQuestionTest')) {
			//Creation of inputs
			foreach ($this->object->getInputs() as $input_name => $q_input) {
				$exists = FALSE;
				foreach ($test->getTestInputs() as $input) {
					if ($input->getTestInputName() == $input_name) {
						if (isset($_REQUEST[$input->getTestInputName()])) {
							$input->setTestInputValue($_REQUEST[$input->getTestInputName()]);
							$input->checkTestInput();
							$input->save();
							$exists = TRUE;
						}
					}
				}

				//Correct current mistakes
				if (!$exists) {
					$new_test_input = new assStackQuestionTestInput(-1, $this->object->getId(), $testcase_name);
					$new_test_input->setTestInputName($input_name);
					$new_test_input->setTestInputValue("");
					$new_test_input->save();
				}
			}


			//Creation of expected results
			foreach ($test->getTestExpected() as $index => $prt) {
				if (isset($_REQUEST['score_' . $prt->getTestPRTName()])) {
					$prt->setExpectedScore(ilUtil::stripSlashes($_REQUEST['score_' . $prt->getTestPRTName()]));
				}
				if (isset($_REQUEST['penalty_' . $prt->getTestPRTName()])) {
					$prt->setExpectedPenalty(ilUtil::stripSlashes($_REQUEST['penalty_' . $prt->getTestPRTName()]));
				}
				if (isset($_REQUEST['answernote_' . $prt->getTestPRTName()])) {
					$prt->setExpectedAnswerNote(ilUtil::stripSlashes($_REQUEST['answernote_' . $prt->getTestPRTName()]));
				}
				$prt->checkTestExpected();
				$prt->save();
			}
		}

		$this->showUnitTests();
	}

	/*
	 * Command for create testcases
	 */
	public function createTestcases()
	{
		global $DIC;
		$tabs = $DIC->tabs();
		//Set all parameters required
		$this->plugin->includeClass('utils/class.assStackQuestionStackFactory.php');
		$tabs->activateTab('edit_properties');
		$tabs->activateSubTab('unit_tests');
		$this->getQuestionTemplate();

		//Create GUI object
		$this->plugin->includeClass('GUI/test/class.assStackQuestionTestGUI.php');
		$unit_test_gui = new assStackQuestionTestGUI($this, $this->plugin);

		//Add CSS
		$DIC->globalScreen()->layout()->meta()->addCss($this->plugin->getStyleSheetLocation('css/qpl_xqcas_unit_tests.css'));

		//Returns Deployed seeds form
		$testcase_name = assStackQuestionUtils::_getNewTestCaseNumber($this->object->getId());
		$this->tpl->setVariable("QUESTION_DATA", $unit_test_gui->createTestcaseForm($testcase_name, $this->object->getInputs(), $this->object->getPotentialResponsesTrees()));
	}

	/*
	 * Calling command for create testcases
	 */
	public function doCreateTestcase()
	{
		//boolean correct
		$testcase = assStackQuestionUtils::_getNewTestCaseNumber($this->object->getId());
		$new_test = new assStackQuestionTest(-1, $this->object->getId(), $testcase);

		//Creation of inputs
		foreach ($this->object->getInputs() as $input_name => $input) {
			$new_test_input = new assStackQuestionTestInput(-1, $this->object->getId(), $testcase);
			$new_test_input->setTestInputName($input_name);

			if (isset($_REQUEST[$input_name])) {
				$new_test_input->setTestInputValue(ilUtil::stripSlashes($_REQUEST[$input_name]));
			} else {
				$new_test_input->setTestInputValue("");
			}

			$new_test_input->save();
			$test_inputs[] = $new_test_input;
		}

		//Creation of expected results
		foreach ($this->object->getPotentialResponsesTrees() as $prt_name => $prt) {
			//Getting the PRT name
			$new_test_expected = new assStackQuestionTestExpected(-1, $this->object->getId(), $testcase, $prt_name);

			if (isset($_REQUEST['score_' . $prt_name])) {
				$new_test_expected->setExpectedScore(ilUtil::stripSlashes($_REQUEST['score_' . $prt_name]));
			} else {
				$new_test_expected->setExpectedScore("");
			}

			if (isset($_REQUEST['penalty_' . $prt_name])) {
				$new_test_expected->setExpectedPenalty(ilUtil::stripSlashes($_REQUEST['penalty_' . $prt_name]));
			} else {
				$new_test_expected->setExpectedPenalty("");
			}

			if (isset($_REQUEST['answernote_' . $prt_name])) {
				$new_test_expected->setExpectedAnswerNote(ilUtil::stripSlashes($_REQUEST['answernote_' . $prt_name]));
			} else {
				$new_test_expected->setExpectedAnswerNote("");
			}
			$new_test_expected->save();
			$test_expected[] = $new_test_expected;
		}

		$new_test->setTestExpected($test_expected);
		$new_test->setTestInputs($test_inputs);
		$new_test->save();

		$this->showUnitTests();
	}

	/*
	 * Command for deleting testcases
	 */
	public function doDeleteTestcase()
	{
		//get Post vars
		if (isset($_POST['test_id'])) {
			$test_id = $_POST['test_id'];
		}
		if (isset($_POST['question_id'])) {
			$question_id = $_POST['question_id'];
		}
		if (isset($_POST['testcase_name'])) {
			$testcase_name = $_POST['testcase_name'];
		} else {
			$testcase_name = FALSE;
		}

		$new_tests = assStackQuestionTest::_read($question_id, $testcase_name);
		$new_test = $new_tests[$testcase_name];
		$new_test->delete($question_id, $testcase_name);

		$this->showUnitTests();
	}

	/* GETTERS AND SETTERS */

	/**
	 * @return ilassStackQuestionPlugin
	 */
	public function getPlugin(): ilPlugin
	{
		return $this->plugin;
	}

	/**
	 * @param ilassStackQuestionPlugin $plugin
	 */
	public function setPlugin(ilPlugin $plugin): void
	{
		$this->plugin = $plugin;
	}

	/**
	 * @return string
	 */
	public function getRteModule(): string
	{
		return $this->rte_module;
	}

	/**
	 * @param string $rte_module
	 */
	public function setRteModule(string $rte_module): void
	{
		$this->rte_module = $rte_module;
	}

	/**
	 * Get a list of allowed RTE tags
	 * This is used for ilUtil::stripSpashes() when saving the RTE fields
	 *
	 * @return string    allowed html tags, e.g. "<em><strong>..."
	 */
	public function getRTETags()
	{
		if (empty($this->rte_tags)) {
			$this->initRTESupport();
		}

		return '<' . implode('><', $this->rte_tags) . '>';
	}

	/**
	 * @param array $rte_tags
	 */
	public function setRteTags(array $rte_tags): void
	{
		$this->rte_tags = $rte_tags;
	}

	/**
	 * @return array
	 */
	public function getIsPreview(): array
	{
		return $this->is_preview;
	}

	/**
	 * @param array $is_preview
	 */
	public function setIsPreview(array $is_preview): void
	{
		$this->is_preview = $is_preview;
	}


}