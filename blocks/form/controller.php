<?php 
defined('C5_EXECUTE') or die("Access Denied.");
class FormBlockController extends Concrete5_Controller_Block_Form {
    
		
	/** 
	 * Used for localization. If we want to localize the name/description we have to include this
	 */
	public function getBlockTypeDescription() {
		return t("Build simple forms and surveys for Japanese.");
	}
	
	public function getBlockTypeName() {
		return t("Form");
	}
	


	//users submits the completed survey
	function action_submit_form() { 
	
		$ip = Loader::helper('validation/ip');
		Loader::library("file/importer");
		
		if (!$ip->check()) {
			$this->set('invalidIP', $ip->getErrorMessage());			
			return;
		}	

		$txt = Loader::helper('text');
		$db = Loader::db();
		
		//question set id
		$qsID=intval($_POST['qsID']); 
		if($qsID==0)
			throw new Exception(t("Oops, something is wrong with the form you posted (it doesn't have a question set id)."));
			
		//get all questions for this question set
		$rows=$db->GetArray("SELECT * FROM {$this->btQuestionsTablename} WHERE questionSetId=? AND bID=? order by position asc, msqID", array( $qsID, intval($this->bID)));			

		// check captcha if activated
		if ($this->displayCaptcha) {
			$captcha = Loader::helper('validation/captcha');
			if (!$captcha->check()) {
				$errors['captcha'] = t("Incorrect captcha code");
				$_REQUEST['ccmCaptchaCode']='';
			}
		}
		
		//checked required fields
		foreach($rows as $row){
			if ($row['inputType']=='datetime'){
				if (!isset($datetime)) {
					$datetime = Loader::helper("form/date_time");
				}
				$translated = $datetime->translate('Question'.$row['msqID']);
				if ($translated) {
					$_POST['Question'.$row['msqID']] = $translated;
				}
			}
			if( intval($row['required'])==1 ){
				$notCompleted=0;
				if ($row['inputType'] == 'email') {
					if (!Loader::helper('validation/strings')->email($_POST['Question' . $row['msqID']])) {
						$errors['emails'] = t('You must enter a valid email address.');
					}
				}
				if($row['inputType']=='checkboxlist'){
					$answerFound=0;
					foreach($_POST as $key=>$val){
						if( strstr($key,'Question'.$row['msqID'].'_') && strlen($val) ){
							$answerFound=1;
						} 
					}
					if(!$answerFound) $notCompleted=1;
				}elseif($row['inputType']=='fileupload'){		
					if( !isset($_FILES['Question'.$row['msqID']]) || !is_uploaded_file($_FILES['Question'.$row['msqID']]['tmp_name']) )					
						$notCompleted=1;
				}elseif( !strlen(trim($_POST['Question'.$row['msqID']])) ){
					$notCompleted=1;
				} 
				if($notCompleted) $errors['CompleteRequired'] = t("Complete required fields *") ; 
			}
		}
		
		//try importing the file if everything else went ok	
		$tmpFileIds=array();	
		if(!count($errors))	foreach($rows as $row){
			if( $row['inputType']!='fileupload' ) continue;
			$questionName='Question'.$row['msqID']; 			
			if	( !intval($row['required']) && 
			   		( 
			   		!isset($_FILES[$questionName]['tmp_name']) || !is_uploaded_file($_FILES[$questionName]['tmp_name'])
			   		) 
				){
					continue;
			}
			$fi = new FileImporter();
			$resp = $fi->import($_FILES[$questionName]['tmp_name'], $_FILES[$questionName]['name']);
			if (!($resp instanceof FileVersion)) {
				switch($resp) {
					case FileImporter::E_FILE_INVALID_EXTENSION:
						$errors['fileupload'] = t('Invalid file extension.');
						break;
					case FileImporter::E_FILE_INVALID:
						$errors['fileupload'] = t('Invalid file.');
						break;
					
				}
			}else{
				$tmpFileIds[intval($row['msqID'])] = $resp->getFileID();
				if(intval($this->addFilesToSet)) {
					Loader::model('file_set');
					$fs = new FileSet();
					$fs = $fs->getByID($this->addFilesToSet);
					if($fs->getFileSetID()) {
						$fs->addFileToSet($resp);
					}
				}
			}
		}
		
		if(count($errors)){			
			$this->set('formResponse', t('Please correct the following errors:') );
			$this->set('errors',$errors);
		}elseif(isset($_REQUEST["fRegist"]) && $_REQUEST["fRegist"] == 1){ //no form errors			
			//save main survey record	
			$u = new User();
			$uID = 0;
			if ($u->isRegistered()) {
				$uID = $u->getUserID();
			}
			$q="insert into {$this->btAnswerSetTablename} (questionSetId, uID) values (?,?)";
			$db->query($q,array($qsID, $uID));
			$answerSetID=$db->Insert_ID();
			$this->lastAnswerSetId=$answerSetID;
			
			$questionAnswerPairs=array();

			if( strlen(FORM_BLOCK_SENDER_EMAIL)>1 && strstr(FORM_BLOCK_SENDER_EMAIL,'@') ){
				$formFormEmailAddress = FORM_BLOCK_SENDER_EMAIL;
			}else{
				$adminUserInfo=UserInfo::getByID(USER_SUPER_ID);
				$formFormEmailAddress = $adminUserInfo->getUserEmail();
			}
			$replyToEmailAddress = $formFormEmailAddress;
			//loop through each question and get the answers 
			foreach( $rows as $row ){	
				//save each answer
				$answerDisplay = '';
				if($row['inputType']=='checkboxlist'){
					$answer = Array();
					$answerLong="";
					$keys = array_keys($_POST);
					foreach ($keys as $key){
						if (strpos($key, 'Question'.$row['msqID'].'_') === 0){
							$answer[]=$txt->sanitize($_POST[$key]);
						}
					}
				}elseif($row['inputType']=='text'){
					$answerLong=$txt->sanitize($_POST['Question'.$row['msqID']]);
					$answer='';
				}elseif($row['inputType']=='fileupload'){
					$answerLong="";
					$answer=intval( $tmpFileIds[intval($row['msqID'])] );
					if($answer > 0) {
						$answerDisplay = File::getByID($answer)->getVersion()->getDownloadURL();
					}
					else {
						$answerDisplay = t('No file specified');
					}
				}elseif($row['inputType']=='url'){
					$answerLong="";
					$answer=$txt->sanitize($_POST['Question'.$row['msqID']]);
				}elseif($row['inputType']=='email'){
					$answerLong="";
					$answer=$txt->sanitize($_POST['Question'.$row['msqID']]);
					if(!empty($row['options'])) {
						$settings = unserialize($row['options']);
						if(is_array($settings) && array_key_exists('send_notification_from', $settings) && $settings['send_notification_from'] == 1) {
							$email = $txt->email($answer);
							if(!empty($email)) {
								$replyToEmailAddress = $email;
							}
						}
					}
				}elseif($row['inputType']=='telephone'){
					$answerLong="";
					$answer=$txt->sanitize($_POST['Question'.$row['msqID']]);
				}else{
					$answerLong="";
					$answer=$txt->sanitize($_POST['Question'.$row['msqID']]);
				}
				
				if( is_array($answer) ) 
					$answer=join(',',$answer);
									
				$questionAnswerPairs[$row['msqID']]['question']=$row['question'];
				$questionAnswerPairs[$row['msqID']]['answer']=$txt->sanitize( $answer.$answerLong );
				$questionAnswerPairs[$row['msqID']]['answerDisplay'] = strlen($answerDisplay) ? $answerDisplay : $questionAnswerPairs[$row['msqID']]['answer'];
				
				$v=array($row['msqID'],$answerSetID,$answer,$answerLong);
				$q="insert into {$this->btAnswersTablename} (msqID,asID,answer,answerLong) values (?,?,?,?)";
				$db->query($q,$v);
			}
			$foundSpam = false;
			
			$submittedData = '';
			foreach($questionAnswerPairs as $questionAnswerPair){
				$submittedData .= $questionAnswerPair['question']."\r\n".$questionAnswerPair['answer']."\r\n"."\r\n";
			} 
			$antispam = Loader::helper('validation/antispam');
			if (!$antispam->check($submittedData, 'form_block')) { 
				// found to be spam. We remove it
				$foundSpam = true;
				$q="delete from {$this->btAnswerSetTablename} where asID = ?";
				$v = array($this->lastAnswerSetId);
				$db->Execute($q, $v);
				$db->Execute("delete from {$this->btAnswersTablename} where asID = ?", array($this->lastAnswerSetId));
			}
			
			if(intval($this->notifyMeOnSubmission)>0 && !$foundSpam){	
				if( strlen(FORM_BLOCK_SENDER_EMAIL)>1 && strstr(FORM_BLOCK_SENDER_EMAIL,'@') ){
					$formFormEmailAddress = FORM_BLOCK_SENDER_EMAIL;  
				}else{ 
					$adminUserInfo=UserInfo::getByID(USER_SUPER_ID);
					$formFormEmailAddress = $adminUserInfo->getUserEmail(); 
				}
				
				$mh = Loader::helper('mail');
				$mh->to( $this->recipientEmail ); 
				$mh->from( $formFormEmailAddress ); 
				$mh->replyto( $replyToEmailAddress ); 
				$mh->addParameter('formName', $this->surveyName);
				$mh->addParameter('questionSetId', $this->questionSetId);
				$mh->addParameter('questionAnswerPairs', $questionAnswerPairs); 
				$mh->load('block_form_submission');
				$mh->setSubject(t('%s Form Submission', $this->surveyName).":".time());
				//echo $mh->body.'<br>';
				@$mh->sendMail(); 
			} 
			
			if (!$this->noSubmitFormRedirect) {
				if ($this->redirectCID > 0) {
					$pg = Page::getByID($this->redirectCID);
					if (is_object($pg) && $pg->cID) {
						$this->redirect($pg->getCollectionPath());
					}
				}
				$c = Page::getCurrentPage();
				header("Location: ".Loader::helper('navigation')->getLinkToCollection($c, true)."?surveySuccess=1&qsid=".$this->questionSetId."#".$this->questionSetId);
				exit;
			}
		}else{
		    $questions = array();
    		foreach($rows as $row){
    		    $row += array("value"=>$_POST['Question'.$row['msqID']]);
    		    $questions[] = $row;
    		}
    		$this->set("questions",$questions);
		}
	}

}
class FormBlockStatistics extends Concrete5_Controller_Block_FormStatistics {}
class MiniSurvey extends Concrete5_Controller_Block_FormMinisurvey {}
