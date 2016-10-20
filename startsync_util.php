<?php

//
// functions
//


function proUpdateStatus($id, $description, $status, $message)
{
	$escaped_msg = mysql_real_escape_string($message);
        $query = "REPLACE INTO #__prodep_cron_status
			(step_id, step_description, step_status, step_message, update_time)
			VALUES('$id', '$description', '$status', '$escaped_msg', now())";
		
		proRunQuery($query, "UPDATE");
}

function getMembershipCountByType($user_records, $user_ids, $m_type)
{
	$pmp_count = 0;

	foreach ($user_ids as $user_id) {
		$user_record = $user_records[$user_id];
		$designation = preg_replace("/\s+/", "", $user_record['Designation']);

		// one user could have multiple memberships
		// add the count to each membership
		$m_names = preg_split("/,/", $designation);

		foreach ($m_names as $m_name) {
			if ($m_name == $m_type) {
				$pmp_count++;
			}
		}
	}

	return $pmp_count;
}

function blockUsers($user_ids)
{
	foreach ($user_ids as $user_id) {
		if (trim($user_id) != "") {
			// get user info
			$query = "UPDATE #__users SET block='1'
				WHERE (usertype != 'Super Administrator') AND id in (
					select user_id from #__comprofiler where proteonkey='$user_id')";
			proRunQuery($query, "UPDATE");
		}
	}
}

function unblockUsers($user_ids)
{
	foreach ($user_ids as $user_id) {
		if (trim($user_id) != "") {
			// get user info
			$query = "UPDATE #__users SET block='0'
				WHERE (usertype != 'Super Administrator') AND id in (
					select user_id from #__comprofiler where proteonkey='$user_id')";
			proRunQuery($query, "UPDATE");
		}
	}
}

function blockRegisteredUsers($mysqli)
{
	// block all registered users
	$query = "UPDATE #__users SET block='1'
		WHERE upper(usertype)='".strtoupper('Registered')."'";
	
	if ($mysqli->query($query)) {
		// update succeeded
	} else {
		proExit("NOTOK|".$mysqli->error."|".$query);
	} // end else

	// update blockalluser
	$query = "UPDATE #__prodep_config SET blockalluser='3'";

	if ($mysqli->query($query)) {
		// update succeeded
	} else {
		proExit("NOTOK|".$mysqli->error."|".$query);
	} // end else
}

function unblockRegisteredUsers($mysqli)
{
	// block all registered users
	$query = "UPDATE #__users SET block='0'
		WHERE upper(usertype)='".strtoupper('Registered')."'";
	
	if ($mysqli->query($query)) {
		// update succeeded
	} else {
		proExit("NOTOK|".$mysqli->error."|".$query);
	} // end else

	// update blockalluser
	$query = "UPDATE #__prodep_config SET blockalluser='0'";

	if ($mysqli->query($query)) {
		// update succeeded
	} else {
		proExit("NOTOK|".$mysqli->error."|".$query);
	} // end else
}

function updateJosUserRegisterDate($user_id, $register_date)
{
	$query = "UPDATE #__users SET registerDate='$register_date' WHERE id='$user_id'";
	proRunQuery($query, "UPDATE");
}

function updateJosUserEmail($user_id, $email)
{
	if (josEmailHasChanged($user_id, $email)) {
		$query = "UPDATE #__users SET email='$email' WHERE id='$user_id'";
		proRunQuery($query, "UPDATE");
	}
}

function updateJnewsUserEmail($user_id, $email)
{
	if (($jnews_id = getJnewsEmailChangedId($user_id, $email)) > 0) {
		// update will fail if there is another entry with the same email
		// in that case, delete that other entry
		if (isJnewsDuplicateEmailExists($user_id, $email)) {
			$query = "DELETE from #__jnews_subscribers WHERE email='".$email."'";
			proRunQuery($query, "UPDATE");
		}

		$query = "UPDATE #__jnews_subscribers SET email='$email' WHERE id='$jnews_id'";
		proRunQuery($query, "UPDATE");
	}
}

function josEmailHasChanged($user_id, $email)
{
	$emailChanged = false;

	// there must be a record for the given user id
	// if there is no record matching both id and email, assume that email has changed
	$query = "SELECT id FROM #__users where id='$user_id' AND upper(email)='".strtoupper($email)."'";
	$result = proRunQuery($query, "SELECTOBJ");

	if (count($result) == 0) {
		$emailChanged = true;
	}

	return $emailChanged;
}

function getJnewsEmailChangedId($user_id, $email)
{
	$changedId = 0;

	// there must be a record for the given user id
	// if there are record(s) matching user id but not email, assume that email has changed
	// it is possible, a user has multiple records indicating that the user may want
	// .. to receive news updates on all those emails
	// in that case, use the oldest record to verify email change
	$query = "SELECT id FROM #__jnews_subscribers
		where user_id='$user_id' AND upper(email)!='".strtoupper($email)."' order by id";
	$result = proRunQuery($query, "SELECTOBJ");
	
	if (count($result) > 0) {
		foreach ($result as $row) {
			$changedId = $row->id;
		}
	}

	return $changedId;
}

function sendEmailNotification($notify_type, $email_config, $fullname, $email, $username_pwd)
{
	$subject = $email_config["email_subject"];
	$message = $email_config["email_body"];
	$message = nl2br($email_config["email_body"]);
	$subject = str_replace('[FULLNAME]', $fullname, $subject);
	$message = str_replace('[FULLNAME]', $fullname, $message);
	
	if ($notify_type == "NEW") {
		$message = str_replace('[USERNAME]', $username_pwd["username"], $message);
		$message = str_replace('[PASSWORD]', $username_pwd["passwdclear"], $message);
	}

	$headers  = 'MIME-Version: 1.0' . "\r\n";
	$headers .= 'Content-type: text/html; charset=UTF-8' . "\r\n";
	$headers .= "From: ".$email_config["from_name"].' <'.$email_config["from_email"].'>'."\r\n";
	$headers .= "Reply-To: ".$email_config["from_name"].' <'.$email_config["from_email"].'>'."\r\n";
	$headers .= "Bcc: " .$email_config["cc_email"]."\r\n";

	$mail_sent = mail($email, $subject, $message, $headers);
}

function addUsers($wsconfig, $user_records, $new_user_ids, $user_group_id, $user_group_name)
{
	foreach ($new_user_ids as $new_id) {
		$user_record = $user_records[$new_id];
		$username_pwd = $user_records["usernamepwd"][$new_id];
		$userstatus = $wsconfig["blocknewuser"];  // block new users? 0 - no / 1 - yes

		// add user record to #__users table
		addUserToJosUsers($userstatus, $user_record, $username_pwd, $user_group_name);
		
		$new_id = getMaxValue("#__users", "id");
		$comprofiler_id = getMaxValue("#__comprofiler", "id") + 1;

		// #__users and #__comprofiler must be in sync
		// if not, it is an error
		if ($new_id != $comprofiler_id) {
			// see if there is an entry in #__comprofiler with the same id as $new_id
			// if not, use that as the id for the new user to add entry to #__compiler
			// if another entry exists with the same id as $new_id, then it is an error
			if (isJosComprofilerInSyncWithJosUsers($new_id)) {
				$comprofiler_id = $new_id;
			} else {
				// send an alert email and exit
				sendOutOfSyncEmailAlert($wsconfig["dep_alertemail"], $wsconfig["dep_alertsubject"], $new_id, $comprofiler_id);
				proExit("NOTOK|#__users and #__comprofiler not in sync|out of sync new user id: ".$new_id);
			}
		}

		// add user record to #__core_acl_groups_aro_map table
		addUserToGroups($new_id, $user_group_id);
		
		// add user record to #__comprofiler table
		updateJosComprofiler($new_id, $comprofiler_id, $user_record, "ADD");
		
		// skip adding to acajoom; not needed
		
		// add user record to #__jnews_subscribers
		$jnews_id = getMaxValue("#__jnews_subscribers", "id") + 1;
		addUserToJosNews($new_id, $jnews_id, $user_record);
		
		/*
		 * *************** THIS IS WRONG. SHOULD NOT BE ADDING ENTRIES DIRECTLY TO THE QUEUE.
		 * --Raj (Sun 10/10/2011)
		 */
		// add user record to #__jnews_queue
		//$jnews_queue_id = getMaxValue("#__jnews_queue", "qid") + 1;
		//addUserToJosNewsQueue($mysqli, $jnews_id, $jnews_queue_id);
		
		// add users to default news lists
		addUserToJosNewsLists($jnews_id, $user_record["Zip"], "NEW");

//		error_log("new user: group id -- $user_group_id / group name -- $user_group_name \n", 3, "/tmp/prodep.log");
	}
}

function updateUsers($user_records, $pmi_ids)
{
	foreach ($pmi_ids as $pmi_id) {
		if (trim($pmi_id) != "") {
			$query = "SELECT id, user_id, cb_prodep_depid FROM #__comprofiler
				where cb_prodep_depid is not null and (trim(cb_prodep_depid) != '') and
				cb_prodep_depid='".$pmi_id."'";
			$result = proRunQuery($query, "SELECTOBJ");

			foreach ($result as $row) {
				$pmiid = $row->cb_prodep_depid;
				$user_record = $user_records[$pmiid];
	
				// update #__comprofiler
				updateJosComprofiler($row->user_id, $row->id, $user_record, "UPDATE");
	
				// email to use
				$email = mysql_real_escape_string(trim($user_record['Email']));
	
				if ($email != "") {
					// update email address of all users if they have changed since the last update
					updateJosUserEmail($row->user_id, $email);
	
					// update email address of all jnews users if they have changed since the last update
					updateJnewsUserEmail($row->user_id, $email);
				}
	
				// update register date on #__users
				updateJosUserRegisterDate($row->user_id, $user_record["PMIJoinDate"]);
			}
		}
	}
}

function proRebuildJnewsSubscriptionLists()
{
	// rebuild jnews subscription lists
	
	/*
	 * implement the following logic from Mike H.
	 * 
	 * IF the subscriber’s zipcode is on the list
	 * — IF the subscriber is not already subscribed
	 * — — IF the subscriber is NOT on the unsubscribed list (Check image below).
	 * — — THEN add the subscriber to the list
	 * ELSE do nothing
	 *     -- dated 7/26/14
	 */
	$query = "SELECT js.id,p.cb_prodep_zip
		FROM #__jnews_subscribers js, #__comprofiler p
		WHERE js.user_id=p.user_id";
	$result = proRunQuery($query, "SELECTOBJ");
	
	if (count($result) > 0) {
		/* fetch object array */
		foreach ($result as $obj) {
			addUserToJosNewsLists($obj->id, $obj->cb_prodep_zip, 'EXISTING');
		}
	}
}

function addUserToJosNewsLists($jnews_id, $user_zip, $user_type)
{
	if ($user_type == 'NEW') {
		// delete any existing entries with the same subscriber id
		// add user subscriber to default new lists
		$query = "DELETE FROM #__jnews_listssubscribers WHERE subscriber_id=".$jnews_id;
		proRunQuery($query, "UPDATE");
		
		// add user to default news lists
		$query = "INSERT INTO #__jnews_listssubscribers (list_id, subscriber_id, `subdate`)
			SELECT pro.list_id,".$jnews_id.",unix_timestamp(now()) FROM #__prodep_subscribesetup pro
			WHERE pro.subscribe=1";
		proRunQuery($query, "UPDATE");
	}
	
	// add user to branch news lists
	$query = "SELECT list_id,extra_fields FROM #__prodep_subscribesetup WHERE subscribe=2";
	$result = proRunQuery($query, "SELECTOBJ");
	$zip_matched_with_list = FALSE;
	
	if (count($result) > 0) {
		foreach ($result as $row) {
			$list_id = $row->list_id;
			$extra_fields = json_decode($row->extra_fields, true);
			$zipcodes = explode(',', $extra_fields['ZipCodes']);
			
//			if (array_search($user_zip, $zipcodes) === FALSE) {
			if (isUserZipMatchesNewslistZips($user_zip, $zipcodes) === FALSE) {
				// do nothing
			} else {
				if (! isUserAlreadySubscribedToJnewsList($list_id, $jnews_id)
						&& ! isUserUnsubscribedFromJnewsList($list_id, $jnews_id)) {
					// current news list has user's zip
					// add user to current news list
					$query = "INSERT INTO #__jnews_listssubscribers (list_id, subscriber_id, `subdate`)
						VALUES (".$list_id.",".$jnews_id.",unix_timestamp(now()))";
					proRunQuery($query, "UPDATE");
				}

				$zip_matched_with_list = TRUE;
			}
		}
		
		// if user zip does not match any of the zip codes assgigned to news lists,
		// .. add user zip to DEP exceptions so that admin can take appropriate action
		if ($zip_matched_with_list === FALSE) {
			// add user zip to DEP exceptions list
			// get the id of the inserted record
			$query = " SELECT id FROM #__jnews_lists WHERE list_name='DEP Exceptions' ";
			$result = proRunQuery($query, "SELECTOBJ");
			
			foreach ($result as $row) {
				$list_id = $row->id;
				$query = "INSERT INTO #__jnews_listssubscribers (list_id, subscriber_id, `subdate`)
					VALUES (".$list_id.",".$jnews_id.",unix_timestamp(now()))";
				proRunQuery($query, "UPDATE");
			}
		}
	}
}

function isUserZipMatchesNewslistZips($userZip, $zipcodes)
{
	$result = FALSE;
	$user_zip = trim($userZip);

	/*
	 * Check if user's zip code is one of the zipcodes that the current
	 * news list is assigned to. For now, just check to see if user zip
	 * starts with any of the zip codes in the news list. 
	 */
	foreach ($zipcodes as $zip) {
		if ((string)strpos($userZip, $zip) === (string)0) {
			$result = TRUE;
			break;
		}
	}
	
	return $result;
}

function isUserAlreadySubscribedToJnewsList($list_id, $subscriber_id)
{
	$subscribed = FALSE;
	
	$query = "SELECT * FROM #__jnews_listssubscribers where list_id='".$list_id."'"
		. " AND subscriber_id='".$subscriber_id."'"
		. " AND unsubscribe=0";
	$result = proRunQuery($query, "SELECTOBJ");
	
	if (count($result) > 0) {
		$subscribed = true;
	}

	return $subscribed;
}

function isUserUnsubscribedFromJnewsList($list_id, $subscriber_id)
{
	$unsubscribed = FALSE;
	
	$query = "SELECT * FROM #__jnews_listssubscribers where list_id='".$list_id."'"
		. " AND subscriber_id='".$subscriber_id."'"
		. " AND unsubscribe=1"
		. " AND unsubdate=(
				select max(unsubdate) FROM #__jnews_listssubscribers
				WHERE list_id='".$list_id."'"
		. " AND subscriber_id='".$subscriber_id."'"
		. " AND unsubscribe=1)";
	$result = proRunQuery($query, "SELECTOBJ");
	
	if (count($result) > 0) {
		$unsubscribed = true;
	}

	return $unsubscribed;
}

function addUserToJosNewsQueue($mysqli, $jnews_id, $jnews_queue_id)
{
	// given email is not subscribed to jnews
	// add the email to subscription
	$query = "INSERT INTO #__jnews_queue (qid, type, issue_nb, subscriber_id, mailing_id, send_date, suspend, delay, acc_level, published)"
		." VALUES ('$jnews_queue_id', '1', '0', '$jnews_id', '0' , '0000-00-00 00:00:00' , '0' , '0' , '0' , '0')";

	if ($mysqli->query($query)) {
		// insert succeeded
 	} else {
		proExit("NOTOK|".$mysqli->error."|".$query);
	} // end else
}
						
function addUserToJosNews($new_id, $jnews_id, $user_record)
{
	$fullname     =       mysql_real_escape_string($user_record["FullName"]);
	$email        =       mysql_real_escape_string($user_record["Email"]);
			
	$query = "SELECT * FROM #__jnews_subscribers where email ='$email'";
	$result = proRunQuery($query, "SELECTOBJ");

	if (count($result)  == 0) {
		// given email is not subscribed to jnews
		// add the email to subscription
		$query = "INSERT INTO #__jnews_subscribers (id, user_id, name, email, receive_html, confirmed, blacklist, timezone, language_iso, subscribe_date, params)"
			." VALUES ('$jnews_id', '$new_id', '$fullname', '$email', '1', '1' , '0' , '00:00:00' , 'eng' , unix_timestamp(now()), '')";
		proRunQuery($query, "UPDATE");
	}
}

function updateJosComprofiler($user_id, $comprofiler_id, $user_record, $query_type)
{
	if ($query_type == "ADD") {
		$query = "INSERT INTO";
	} else if ($query_type == "UPDATE") {
		$query = "UPDATE";
	} else {
		proExit("NOTOK|Invalid query type|".$query_type);
	}

	$fullname     =       mysql_real_escape_string($user_record["FullName"]);
	$email        =       mysql_real_escape_string($user_record["Email"]);
	$firstname    =       mysql_real_escape_string($user_record["FirstName"]);
	$lastname     =       mysql_real_escape_string($user_record["LastName"]);
	$prefix       =       $user_record["Prefix"];
	$suffix       =       $user_record["Suffix"];
	$designation  =       $user_record["Designation"];
	$nickname     =       mysql_real_escape_string($user_record["NickName"]);
	$nametype     =       $user_record["NameType"];
	$wtitle       =       $user_record["WTitle"];
	$wcompany     =       mysql_real_escape_string($user_record["WCompany"]);
	$waddress1    =       mysql_real_escape_string($user_record["WAddress1"]);
	$waddress2    =       mysql_real_escape_string($user_record["WAddress2"]);
	$waddress3    =       mysql_real_escape_string($user_record["WAddress3"]);
	$wcity        =       mysql_real_escape_string($user_record["WCity"]);
	$wstate       =       mysql_real_escape_string($user_record["WState"]);
	$wzip         =       $user_record["WZip"];
	$wcountry     =       mysql_real_escape_string($user_record["WCountry"]);
	$wphone       =       $user_record["WPhone"];
	$wphoneext    =       $user_record["WPhoneExt"];
	$wfax         =       $user_record["WFax"];
	$wemail       =       mysql_real_escape_string($user_record["WEmail"]);
	$htitle       =       $user_record["HTitle"];
	$hcompany     =       mysql_real_escape_string($user_record["HCompany"]);
	$haddress1    =       mysql_real_escape_string($user_record["HAddress1"]);
	$haddress2    =       mysql_real_escape_string($user_record["HAddress2"]);
	$haddress3    =       mysql_real_escape_string($user_record["HAddress3"]);
	$hcity        =       mysql_real_escape_string($user_record["HCity"]);
	$hstate       =       mysql_real_escape_string($user_record["HState"]);
	$hzip         =       $user_record["HZip"];
	$hcountry     =       mysql_real_escape_string($user_record["HCountry"]);
	$hphone       =       $user_record["HPhone"];
	$hphoneext    =       $user_record["HPhoneExt"];
	$hfax         =       $user_record["HFax"];
	$hemail       =       mysql_real_escape_string($user_record["HEmail"]);
	$title        =       $user_record["Title"];
	$company      =       mysql_real_escape_string($user_record["Company"]);
	$address1     =       mysql_real_escape_string($user_record["Address1"]);
	$address2     =       mysql_real_escape_string($user_record["Address2"]);
	$address3     =       mysql_real_escape_string($user_record["Address3"]);
	$city         =       mysql_real_escape_string($user_record["City"]);
	$state        =       mysql_real_escape_string($user_record["State"]);
	$zip          =       $user_record["Zip"];
	$country      =       mysql_real_escape_string($user_record["Country"]);
	$phone        =       $user_record["Phone"];
	$phoneext     =       $user_record["PhoneExt"];
	$fax          =       $user_record["Fax"];
	$perfmailaddr =       $user_record["PrefMailAddr"];
	$pmpnumber    =       $user_record["PMPNumber"];
	$pmpdate      =       $user_record["PMPDate"];
	$pmijoindate  =       $user_record["PMIJoinDate"];
	$pmiexpirationdate=   $user_record["PMIExpirationDate"];
	$chapters     =       $user_record["Chapters"];
	$chaptercount =       $user_record["ChapterCount"];
	$sigs         =       $user_record["SIGs"];
	$sigscount    =       $user_record["SIGsCount"];
	$industrycodes    =   $user_record["IndustryCodes"];
	$industrycodecount   =$user_record["IndustryCodeCount"];
	$occupationcodes  =   $user_record["OccupationCodes"];
	$occupationcount  =   $user_record["OccupationCount"];
	$joindate     =       $user_record["JoinDate"];
	$expirationdate   =   $user_record["ExpirationDate"];
	$memberclass  =       $user_record["MemberClass"];
	$membergroup  =       mysql_real_escape_string($user_record["MemberGroup"]);
	$mbrgroup     =       $user_record["MbrGroup"];
	$directory    =       $user_record["Directory"];
	$mailinglist  =       $user_record["MailingList"];
	$recordedited =       $user_record["RecordEdited"];
	$sortkey      =       $user_record["SortKey"];
	$perfphone    =       $user_record["PrefPhone"];
	$prodatadate  =       $user_record["DataDate"];
	$pmiid        =       $user_record["ID"];

	$query = $query." #__comprofiler SET
		id='$comprofiler_id',
		user_id='$user_id',
		firstname='$firstname',
		lastname='$lastname',
		approved='1',
		avatarapproved='1',
		confirmed='1',
		cb_prodep_depid='$pmiid',
		cb_prodep_prefix='$prefix',
		cb_prodep_suffix='$suffix',
		cb_prodep_designation='$designation',
		cb_prodep_nickname='$nickname',
		cb_prodep_nametype='$nametype',
		cb_prodep_wtitle='$wtitle',
		cb_prodep_wcompany='$wcompany',
		cb_prodep_waddress1='$waddress1',
		cb_prodep_waddress2='$waddress2',
		cb_prodep_waddress3='$waddress3',
		cb_prodep_wcity='$wcity',
		cb_prodep_wstate='$wstate',
		cb_prodep_wzip='$wzip',
		cb_prodep_wcountry='$wcountry',
		cb_prodep_wphone='$wphone',
		cb_prodep_wphoneext='$wphoneext',
		cb_prodep_wfax='$wfax',
		cb_prodep_wemail='$wemail',
		cb_prodep_htitle='$htitle',
		cb_prodep_hcompany='$hcompany',
		cb_prodep_haddress1='$haddress1',
		cb_prodep_haddress2='$haddress2',
		cb_prodep_haddress3='$haddress3',
		cb_prodep_hcity='$hcity',
		cb_prodep_hstate='$hstate',
		cb_prodep_hzip='$hzip',
		cb_prodep_hcountry='$hcountry',
		cb_prodep_hphone='$hphone',
		cb_prodep_hphoneext='$hphoneext',
		cb_prodep_hfax='$hfax',
		cb_prodep_hemail='$hemail',
		cb_prodep_title='$title',
		cb_prodep_company='$company',
		cb_prodep_address1='$address1',
		cb_prodep_address2='$address2',
		cb_prodep_address3='$address3',
		cb_prodep_city='$city',
		cb_prodep_state='$state',
		cb_prodep_zip='$zip',
		cb_prodep_country='$country',
		cb_prodep_phone='$phone',
		cb_prodep_phoneext='$phoneext',
		cb_prodep_fax='$fax',
		cb_prodep_perfmailaddr='$perfmailaddr',
		cb_prodep_pmpnumber='$pmpnumber',
		cb_prodep_pmpdate='$pmpdate',
		cb_prodep_pmijoindate='$pmijoindate',
		cb_prodep_pmiexpirationdate='$pmiexpirationdate',
		cb_prodep_chapters='$chapters',
		cb_prodep_chaptercount='$chaptercount',
		cb_prodep_sigs='$sigs',
		cb_prodep_sigscount='$sigscount',
		cb_prodep_industrycodes='$industrycodes',
		cb_prodep_industrycodecount='$industrycodecount',
		cb_prodep_occupationcodes='$occupationcodes',
		cb_prodep_occupationcount='$occupationcount',
		cb_prodep_joindate=str_to_date('$joindate','%d-%b-%Y'),
		cb_prodep_expirationdate='$expirationdate',
		cb_prodep_memberclass='$memberclass',
		cb_prodep_membergroup='$membergroup',
		cb_prodep_mbrgroup='$mbrgroup',
		cb_prodep_directory='$directory',
		cb_prodep_mailinglist='$mailinglist',
		cb_prodep_recordedited='$recordedited',
		cb_prodep_sortkey='$sortkey',
		cb_prodep_perfphone='$perfphone',
		cb_prodep_datadate='$prodatadate'";

	if ($query_type == "ADD") {
		$query = $query.",proteonkey='$pmiid'";
	} else if ($query_type == "UPDATE") {
		$query = $query." WHERE proteonkey='$pmiid'";
	}

	proRunQuery($query, "UPDATE");
}

function addUserToGroups($user_id, $user_group_id)
{
	$query = "INSERT INTO #__user_usergroup_map (user_id, group_id)"
    	." VALUES ($user_id, $user_group_id)";
	proRunQuery($query, "UPDATE");
}

function addUserToJosUsers($userstatus, $user_record, $username_pwd, $user_group_name)
{
	$fullname = mysql_real_escape_string($user_record["FullName"]);
	$email = mysql_real_escape_string($user_record["Email"]);
	$pmijoindate = $user_record["PMIJoinDate"];
	$username = $username_pwd["username"];
	$userpass = $username_pwd["passwd"];
			
	$query = "insert into #__users (name, username, email, password, usertype, block, registerDate)"
		." values('$fullname', '$username', '$email', '$userpass', '$user_group_name', '$userstatus', '$pmijoindate')";
	proRunQuery($query, "UPDATE");
}

function getUserGroupId($user_group_name)
{
	// get the id of the inserted record
	$query = "SELECT id FROM #__usergroups where title='".$user_group_name."'";
	$result = proRunQuery($query, "SELECTOBJ");

	foreach ($result as $row) {
		$user_group_id = $row->id;
	}

	return $user_group_id;
}

function getMaxValue($dbtbl, $col)
{
	// get the id of the inserted record
	$query = "SELECT MAX($col) maxval FROM $dbtbl";
	$result = proRunQuery($query, "SELECTOBJ");
	
	foreach ($result as $row) {
		$maxval = $row->maxval;			
	}
	
	return $maxval;
}

function getUserRecords($wsconfig, $user_ids)
{
	$user_records = array();

	foreach ($user_ids as $user_id) {
		if (trim($user_id) != "") {
			// get user info
			$query = "SELECT * FROM #__prodep_dummydata where ID='".$user_id."'";
			$result = proRunQuery($query, "SELECTASSOC");
			
			foreach ($result as $row) {
		    	$record_id = $row["ID"];
	        	$user_records[$record_id] = $row;
	        	$user_records["usernamepwd"][$record_id] = getUsernamePasswd($wsconfig, $row);
			}
		}
	}

	return $user_records;
}

function getUserRecordColumnNames($mysqli)
{
	$cols = array();
	$idx = 0;

	// get the column names
	$query = "SHOW COLUMNS FROM #__prodep_dummydata";
	
	if ($result = $mysqli->query($query)) {
		while ($row = $result->fetch_object()) {
			$cols[$idx] = trim($row->Field);
			$idx++;
		}

		/* free result set */
	    $result->free();
	} else {
		proExit("NOTOK|".$mysqli->error."|".$query);
	} // end else

	return $cols;
}

function getUsernamePasswd($wsconfig, $user_record)
{
	$user_pwd = array();

	if($wsconfig["dep_passwordtype"] == 'pmi_number'){
		$userpass=md5($user_record["ID"]);
		$userpass_clear=$user_record["ID"];
	}
	if($wsconfig["dep_passwordtype"] == 'last_name'){
		$userpass=md5($user_record["LastName"]);
		$userpass_clear=$user_record["LastName"];
	}
	if($wsconfig["dep_passwordtype"] != 'pmi_number' && $wsconfig["dep_passwordtype"] !='last_name'){
		$userpass=md5($wsconfig["dep_passwordtype"]);
		$userpass_clear=$wsconfig["dep_passwordtype"];
	}
	if($wsconfig["dep_usernametype"] == 'initial_last'){
		$firstname1         =       preg_replace("/[^a-zA-Z0-9]/", "", $user_record["FirstName"]);
		$lastname1          =       preg_replace("/[^a-zA-Z0-9]/", "", $user_record["LastName"]);
		$string_split       =       str_split($firstname1,sizeof($firstname1));
		for($i=0;$i<sizeof($string_split);$i++){
			$string_splitinitial        =       substr($firstname1,-(sizeof($string_split)),$i+1);
			$string_splitinitial        =       strtolower($string_splitinitial);
			$username                   =       $string_splitinitial.$lastname1;

			if (! isUserExists($username)) {
				break;
			}
		}
	}
	if($wsconfig["dep_usernametype"] == 'first_last'){
		$firstname1   =       preg_replace("/[^a-zA-Z0-9]/", "", $user_record["FirstName"]);
		$lastname1    =       preg_replace("/[^a-zA-Z0-9]/", "", $user_record["LastName"]);
		$username     =       $firstname1.".".$lastname1;
		$username =       strtolower($username);
	}
	if($wsconfig["dep_usernametype"] == 'pmi_number'){
		$username = $user_record["ID"];
	}
	if($wsconfig["dep_usernametype"] == 'email_address'){
		$username = $user_record["Email"];
	}
	
	$user_pwd["username"] = $username;
	$user_pwd["passwd"] = $userpass;
	$user_pwd["passwdclear"] = $userpass_clear;
	
	return $user_pwd;
}

function getDepDataProfile()
{
	$profile = array();

	$profile["membershipcount"] = getDepMembershipCount();
	$profile["usersmetadata"] = getDepUsersMetaData();
	
	return $profile;
}

function getDepMembershipCount()
{
	$membership_count = array();
	$total_count = 0;

	$query = "SELECT Designation, count(*) as valcount FROM #__prodep_dummydata group by Designation";
	$result = proRunQuery($query, "SELECTASSOC");
	
	foreach ($result as $row) {
		$designation = preg_replace("/\s+/", "", $row['Designation']);
		
		// one user could have multiple memberships
		// add the count to each membership
		$m_names = preg_split("/,/", $designation);
		
		foreach ($m_names as $m_name) {
			if (isset($membership_count[$m_name])) {
				$membership_count[$m_name] += $row["valcount"];
			} else {
				$membership_count[$m_name] = $row["valcount"];
			}
		}

		$membership_count[":".$designation.":"] = $row["valcount"];
		$total_count += $row["valcount"];
	}

	$membership_count['TOTAL'] = $total_count;
	
	return $membership_count;
}

function getDepUsersMetaData()
{
	$user_count = array();
	$new_user_ids = array();
	$current_user_ids = array();
	$deleted_user_ids = array();
	$invalid_rows = 0;
	$new_users = 0;
	$deleted_users = 0;
	$current_users = 0;
	$total_users = 0;
	$processed = 0;

	// it is possible, received data may have new records (NEW users)
	// .. or may not have records (DELETED users) that are currently in database
	// following union accounts for both by selecting every record on the database
	// .. and comparing it with the received data
	// if received record has null then the user is assumed to be DELETED
	// select every record of the received data and compare it against the record
	// .. in the database
	// if the database record has null then the user is assumed to be NEW
	// we could have done this in 2 steps but we make it in 1 by using union

	$query = "(select c.proteonkey,d.ID from #__comprofiler c left join #__prodep_dummydata d on c.proteonkey=d.ID)"
		." union distinct"
		." (select c.proteonkey,d.ID from #__prodep_dummydata d left join #__comprofiler c on c.proteonkey=d.ID)";
	
	$result = proRunQuery($query, "SELECTASSOC");
	
	foreach ($result as $row) {
		$proteonkey = trim($row['proteonkey']);
		$depid = trim($row['ID']);
		
		if (($proteonkey == "") && ($depid == "")) {
		// invalid rows
			$invalid_rows++;
		} else if (($proteonkey == "") && ($depid != "")) {
			// user exists in received data not in database
			// new user
			$new_user_ids[] = $depid;
			$new_users++;
		} else if (($proteonkey != "") && ($depid == "")) {
			// user exists in database not in received data
			// deleted user
			$deleted_user_ids[] = $proteonkey;
			$deleted_users++;
		} else {
			// user exists in received data and database
			// user is active and current
			$current_user_ids[] = $depid;
			$current_users++;
		}
		
		$processed++;
	}
	
	$user_count["newusers"] = $new_users;
	$user_count["newuserids"] = $new_user_ids;
	$user_count["currentusers"] = $current_users;
	$user_count["currentuserids"] = $current_user_ids;
	$user_count["deletedusers"] = $deleted_users;
	$user_count["deleteduserids"] = $deleted_user_ids;
	$user_count["totalusers"] = $current_users + $new_users;
	$user_count["invalidrows"] = $invalid_rows;
	$user_count["processed"] = $processed;
	
	return $user_count;
}

function isNewUser($mysqli, $user_id)
{
	$newuser = false;

    $query = "SELECT * FROM #__comprofiler where proteonkey='$user_id'";
	
    if ($result = $mysqli->query($query)) {
		if ($result->num_rows == 0) {
			$newuser = true;
		}
	    
	    $result->free();
    }

	return $newuser;
}

function isUserExists($username)
{
	$userexists = false;

    $query = "SELECT * FROM #__users where username='$username'";
	$result = proRunQuery($query, "SELECTOBJ");

	if (count($result) > 0) {
		$userexists = true;
	}

	return $userexists;
}

function isJnewsDuplicateEmailExists($user_id, $email)
{
	$emailexists = false;

    $query = "SELECT * FROM #__jnews_subscribers where email='".$email."'"
    	. " and user_id != '".$user_id."'";
    $result = proRunQuery($query, "SELECTOBJ");
    
	if (count($result) > 0) {
		$emailexists = true;
	}

	return $emailexists;
}

function isJosComprofilerInSyncWithJosUsers($user_id)
{
	$insync = true;

    $query = "SELECT * FROM #__comprofiler where id >= $user_id";
    $result = proRunQuery($query, "SELECTOBJ");
    
	if (count($result) > 0) {
		$insync = false;
	}

	return $insync;
}

function sendOutOfSyncEmailAlert($alertemail, $alertsubject, $new_id, $comprofiler_id)
{
	$headers = "From: ".$alertemail."\r\nReply-To: ".$alertemail."";
	$message = "IDs on #__users and #__comprofilers are not in sync.\n";
	$message .= " Please manually check CB and Joomla.\n";
	$message .= " Program was unable to repair the damage automaticaly.\n";
	$message .= " IDs: Joomla -- $new_id / CB -- $comprofiler_id\n";
	$mail_sent = mail( $alertemail, $alertsubject, $message, $headers );
}

function getFieldValues($str)
{
	$result = array();

	do {
		// possible field separators
		// if first character is a double-quote (") then first field ends with (",)
		// if first character is not a double-quote then first field ends with (,)
		if (substr($str, 0, 1) == "\"") {
			$fields = preg_split("/\",/", $str, 2);
			$first_field = preg_replace("/\"/", "", $fields[0]);  // strip the beginning double-quote
		} else {
			$fields = preg_split("/,/", $str, 2);
			$first_field = $fields[0];
		}

		// save the parsed field
		$result[] = $first_field;
		
		// redo the parsing with the remainder
		if (count($fields) > 1) {
			$str = $fields[1];
		}
	} while (count($fields) > 1);
	
	return $result;
}

function clearDataDumpTable()
{
    $query = "DELETE FROM #__prodep_dummydata";
    proRunQuery($query, "UPDATE");
}

function getWsConfig()
{
	$config = array();
	$cols = array();
	$idx = 0;

	// get the column names
	$query = "SHOW COLUMNS FROM #__prodep_config";
	$result = proRunQuery($query, "SELECTOBJ");
	
	foreach ($result as $row) {
		$cols[$idx] = trim($row->Field);
		$idx++;
	}

	// get config info
	$query = "SELECT * FROM #__prodep_config";
	$result = proRunQuery($query, "SELECTASSOC");

	foreach ($result as $row) {
		foreach ($cols as $col) {
			$config[$col] = $row[$col];
		}
	}

	return $config;
}

function getEmailConfig($conf_id)
{
	$config = array();

	// get config info
	$query = "SELECT * FROM #__prodep_emailconfig  WHERE conf_id=$conf_id";
	$result = proRunQuery($query, "SELECTASSOC");

	foreach ($result as $row) {
	    /* fetch associative array */
	    $config = $row;
	}

	return $config;
}

function getMembershipRecordsFromFile($filepath)
{
	$data = file_get_contents($filepath);

	return $data;
}

function getMembershipRecords($config)
{
    $endpoint_url = $config["dep_endpointurl"];
    $method_name = $config["dep_methodname"];
    $service_name = $config["dep_service"];
    $username = $config["dep_username"];
    $password = $config["dep_password"];
    $method_namespace = $config["dep_methodnamespace"];
    $auth_namespace = $config["dep_authnamespace"];

    // Build Credential Header
    $token = new stdClass;
    $token->Username = new SOAPVar($username, XSD_STRING, null, null, null, $auth_namespace);
    $token->Password = new SOAPVar($password, XSD_STRING, null, null, null, $auth_namespace);
    $wsec = new stdClass;
    $wsec->UsernameToken = new SoapVar($token, SOAP_ENC_OBJECT, null, null, null, $auth_namespace);
    $auth_header = new SOAPHeader($auth_namespace, 'Security', $wsec, true);

	// Build Client Object with Headers
	$arrOptions = array(
	   'soap_version' => SOAP_1_1,
	   'trace'        => 1, // DEBUG
	   'exceptions'   => true, // DEBUG
	   'cache_wsdl'   => WSDL_CACHE_NONE,
	   'features'     => SOAP_SINGLE_ELEMENT_ARRAYS,
	   'location'     => $endpoint_url,
	   'uri'          => $method_namespace
	);

	try {
		$soapClient = new SoapClient(NULL, $arrOptions);
	    $soapClient->__setSoapHeaders(array($auth_header));
	
	    // Build Method Call
	    $arguments = array( /* empty */ );
	    $action = array(
	    	'soapaction' => "$method_namespace/$service_name/$method_name",
			'uri' => $method_namespace
	    );

	    $result = $soapClient->__soapCall($method_name, $arguments, $action);
	}
	catch (Exception $e)
	{
	    // Print out exception
	    echo $e->getMessage();
	    proExit("NOTOK|".$e->getMessage()."|".$endpoint_url);
	}
	
	return $result->ExtractFile;
}

function dumpdata($linearray)
{
	$pmiid = $linearray[0];
	$fullname = mysql_real_escape_string($linearray[1]);
	$prefix = $linearray[2];
	$firstname = mysql_real_escape_string($linearray[3]);
	$lastname = mysql_real_escape_string($linearray[4]);
	$suffix = $linearray[5];
	$designation = $linearray[6];
	$nickname = mysql_real_escape_string($linearray[7]);
	$nametype = $linearray[8];
	$wtitle = $linearray[9];
	$wcompany = mysql_real_escape_string($linearray[10]);
	$waddress1 = mysql_real_escape_string($linearray[11]);
	$waddress2 = mysql_real_escape_string($linearray[12]);
	$waddress3 = mysql_real_escape_string($linearray[13]);
	$wcity = mysql_real_escape_string($linearray[14]);
	$wstate = mysql_real_escape_string($linearray[15]);
	$wzip = $linearray[16];
	$wcountry = mysql_real_escape_string($linearray[17]);
	$wphone = $linearray[18];
	$wphoneext = $linearray[19];
	$wfax = $linearray[20];
	$wemail = mysql_real_escape_string($linearray[21]);
	$htitle = $linearray[22];
	$hcompany = mysql_real_escape_string($linearray[23]);
	$haddress1 = mysql_real_escape_string($linearray[24]);
	$haddress2 = mysql_real_escape_string($linearray[25]);
	$haddress3 = mysql_real_escape_string($linearray[26]);
	$hcity = mysql_real_escape_string($linearray[27]);
	$hstate = mysql_real_escape_string($linearray[28]);
	$hzip = $linearray[29];
	$hcountry = mysql_real_escape_string($linearray[30]);
	$hphone = $linearray[31];
	$hphoneext = $linearray[32];
	$hfax = $linearray[33];
	$hemail = mysql_real_escape_string($linearray[34]);
	$title = $linearray[35];
	$company = mysql_real_escape_string($linearray[36]);
	$address1 = mysql_real_escape_string($linearray[37]);
	$address2 = mysql_real_escape_string($linearray[38]);
	$address3 = mysql_real_escape_string($linearray[39]);
	$city = mysql_real_escape_string($linearray[40]);
	$state = mysql_real_escape_string($linearray[41]);
	$zip = $linearray[42];
	$country = mysql_real_escape_string($linearray[43]);
	$phone = $linearray[44];
	$phoneext = $linearray[45];
	$fax = $linearray[46];
	$email = mysql_real_escape_string($linearray[47]);
	$perfmailaddr = $linearray[48];
	$pmpnumber = $linearray[49];
	$pmpdate = $linearray[50];
	$pmijoindate = $linearray[51];
	$pmiexpirationdate = $linearray[52];
	$chapters = $linearray[53];
	$chaptercount = $linearray[54];
	$sigs = $linearray[55];
	$sigscount = $linearray[56];
	$industrycodes = $linearray[57];
	$industrycodecount = $linearray[58];
	$occupationcodes = $linearray[59];
	$occupationcount = $linearray[60];
	$joindate = $linearray[61];
	$expirationdate = $linearray[62];
	$memberclass = $linearray[63];
	$membergroup = mysql_real_escape_string($linearray[64]);
	$mbrgroup = $linearray[65];
	$directory = $linearray[66];
	$mailinglist = $linearray[67];
	$recordedited = $linearray[68];
	$sortkey = $linearray[69];
	$perfphone = $linearray[70];
	$datadate = $linearray[71];

	/* Fixing Date Format From Pmi.org to Community Builder Start Here */
	if (trim($pmpdate) != "") {
		$pmpdate_explode=explode('-',$pmpdate);
		$pmpdate= date_formatfix($pmpdate_explode[2],$pmpdate_explode[1],$pmpdate_explode[0]);
	}

	if (trim($pmijoindate) != "") {
		$pmijoindate_explode=explode('-',$pmijoindate);
		$pmijoindate= date_formatfix($pmijoindate_explode[2],$pmijoindate_explode[1],$pmijoindate_explode[0]);
	}

	if (trim($pmiexpirationdate) != "") {
		$pmiexpirationdate_explode=explode('-',$pmiexpirationdate);
		$pmiexpirationdate= date_formatfix($pmiexpirationdate_explode[2],$pmiexpirationdate_explode[1],$pmiexpirationdate_explode[0]);
	}

	if (trim($expirationdate) != "") {
		$expirationdate_explode=explode('-',$expirationdate);
		$expirationdate= date_formatfix($expirationdate_explode[2],$expirationdate_explode[1],$expirationdate_explode[0]);
	}
	/* Fixing Date Format From Pmi.org to Community Builder End Here */

//Escaping special characters
	

$fullname = str_replace("'", "&#39;",$fullname);
$prefix = str_replace("'", "&#39;",$prefix);
$firstname = str_replace("'", "&#39;",$firstname);
$lastname = str_replace("'", "&#39;",$lastname);
$suffix = str_replace("'", "&#39;",$suffix);
$designation = str_replace("'", "&#39;",$designation);
$nickname = str_replace("'", "&#39;",$nickname);
$nametype = str_replace("'", "&#39;",$nametype);
$wtitle = str_replace("'", "&#39;",$wtitle);
$wcompany = str_replace("'", "&#39;",$wcompany);
$waddress1 = str_replace("'", "&#39;",$waddress1);
$waddress2 = str_replace("'", "&#39;",$waddress2);
$waddress3 = str_replace("'", "&#39;",$waddress3);
$wcity = str_replace("'", "&#39;",$wcity);
$wstate = str_replace("'", "&#39;",$wstate);
$wzip = str_replace("'", "&#39;",$wzip);
$wcountry =  str_replace("'", "&#39;",$wcountry);
$wphone = str_replace("'", "&#39;",$wphone);
$wphoneext = str_replace("'", "&#39;",$wphoneext);
$wfax = str_replace("'", "&#39;",$wfax);
$wemail = str_replace("'", "&#39;",$wemail);
$htitle = str_replace("'", "&#39;",$htitle);
$hcompany = str_replace("'", "&#39;",$hcompany);
$haddress1 = str_replace("'", "&#39;",$haddress1);
$haddress2 = str_replace("'", "&#39;",$haddress2);
$haddress3 = str_replace("'", "&#39;",$haddress3);
$hcity = str_replace("'", "&#39;",$hcity);
$hstate = str_replace("'", "&#39;",$hstate);
$hzip = str_replace("'", "&#39;",$hzip);
$hcountry = str_replace("'", "&#39;",$hcountry);
$hphone = str_replace("'", "&#39;",$hphone);
$hphoneext = str_replace("'", "&#39;",$hphoneext);
$hfax = str_replace("'", "&#39;",$hfax);
$hemail = str_replace("'", "&#39;",$hemail);
$title = str_replace("'", "&#39;",$title);
$company = str_replace("'", "&#39;",$company);
$address1 = str_replace("'", "&#39;",$address1);
$address2 = str_replace("'", "&#39;",$address2);
$address3 = str_replace("'", "&#39;",$address3);
$city = str_replace("'", "&#39;",$city);
$state = str_replace("'", "&#39;",$state);
$zip = str_replace("'", "&#39;",$zip);
$country = str_replace("'", "&#39;",$country);
$phone = str_replace("'", "&#39;",$phone);
$phoneext = str_replace("'", "&#39;",$phoneext);
$fax = str_replace("'", "&#39;",$fax);
$email = str_replace("'", "&#39;",$email);
$perfmailaddr = str_replace("'", "&#39;",$perfmailaddr);
$pmpnumber = str_replace("'", "&#39;",$pmpnumber);
$pmpdate = str_replace("'", "&#39;",$pmpdate);
$pmijoindate = str_replace("'", "&#39;",$pmijoindate);
$pmiexpirationdate = str_replace("'", "&#39;",$pmiexpirationdate);
$chapters = str_replace("'", "&#39;",$chapters);
$chaptercount = str_replace("'", "&#39;",$chaptercount);
$sigs = str_replace("'", "&#39;",$sigs);
$sigscount = str_replace("'", "&#39;",$sigscount);
$industrycodes = str_replace("'", "&#39;",$industrycodes);
$industrycodecount = str_replace("'", "&#39;",$industrycodecount);
$occupationcodes = str_replace("'", "&#39;",$occupationcodes);
$occupationcount = str_replace("'", "&#39;",$occupationcount);
$joindate = str_replace("'", "&#39;",$joindate);
$expirationdate = str_replace("'", "&#39;",$expirationdate);
$memberclass = str_replace("'", "&#39;",$memberclass);
$membergroup = str_replace("'", "&#39;",$membergroup);
$mbrgroup = str_replace("'", "&#39;",$mbrgroup);
$directory = str_replace("'", "&#39;",$directory);
$mailinglist = str_replace("'", "&#39;",$mailinglist);
$recordedited = str_replace("'", "&#39;",$recordedited);
$sortkey = str_replace("'", "&#39;",$sortkey);
$perfphone = str_replace("'", "&#39;",$perfphone);
$datadate = str_replace("'", "&#39;",$datadate);

//End escaping

	$query_dump="INSERT INTO #__prodep_dummydata(ID,FullName,Prefix,FirstName,LastName,Suffix,Designation,NickName,NameType,WTitle,WCompany,WAddress1,WAddress2,WAddress3,WCity,WState,WZip,WCountry,WPhone,WPhoneExt,WFax,WEmail,HTitle,HCompany,HAddress1,HAddress2,HAddress3,HCity,HState,HZip,HCountry,HPhone,HPhoneExt,HFax,HEmail,Title,Company,Address1,Address2,Address3,City,State,Zip,Country,Phone,PhoneExt,Fax,Email,PrefMailAddr,PMPNumber,PMPDate,PMIJoinDate,PMIExpirationDate,Chapters,ChapterCount,SIGs,SIGsCount,IndustryCodes,IndustryCodeCount,OccupationCodes,OccupationCount,JoinDate,ExpirationDate,MemberClass,MemberGroup,MbrGroup,Directory,MailingList,RecordEdited,SortKey,PrefPhone,DataDate)
      VALUES($pmiid ,
      '$fullname' ,
      '$prefix' ,
      '$firstname' ,
      '$lastname' ,
      '$suffix' ,
      '$designation' ,
      '$nickname' ,
      '$nametype' ,
      '$wtitle' ,
      '$wcompany' ,
      '$waddress1' ,
      '$waddress2' ,
      '$waddress3' ,
      '$wcity' ,
      '$wstate' ,
      '$wzip' ,
      '$wcountry' ,
      '$wphone' ,
      '$wphoneext' ,
      '$wfax' ,
      '$wemail' ,
      '$htitle' ,
      '$hcompany' ,
      '$haddress1' ,
      '$haddress2' ,
      '$haddress3' ,
      '$hcity' ,
      '$hstate' ,
      '$hzip' ,
      '$hcountry' ,
      '$hphone' ,
      '$hphoneext' ,
      '$hfax' ,
      '$hemail' ,
      '$title' ,
      '$company' ,
      '$address1' ,
      '$address2' ,
      '$address3' ,
      '$city' ,
      '$state' ,
      '$zip' ,
      '$country' ,
      '$phone' ,
      '$phoneext' ,
      '$fax' ,
      '$email' ,
      '$perfmailaddr' ,
      '$pmpnumber' ,
      '$pmpdate' ,
      '$pmijoindate' ,
      '$pmiexpirationdate' ,
      '$chapters' ,
      '$chaptercount' ,
      '$sigs' ,
      '$sigscount' ,
      '$industrycodes' ,
      '$industrycodecount' ,
      '$occupationcodes' ,
      '$occupationcount' ,
      '$joindate' ,
      '$expirationdate' ,
      '$memberclass' ,
      '$membergroup' ,
      '$mbrgroup' ,
      '$directory' ,
      '$mailinglist' ,
      '$recordedited' ,
      '$sortkey' ,
      '$perfphone',
      '$datadate')";

	$dump_result = "NOTOK";

	if ($result = proRunQuery($query_dump, "UPDATE")) {
		// insert succeeded
		$dump_result = "OK";
	}

	return $dump_result;
}

function date_formatfix($year,$month,$day){
	if($month=='Jan'){ $month='01'; }
	if($month=='Feb'){ $month='02'; }
	if($month=='Mar'){ $month='03'; }
	if($month=='Apr'){ $month='04'; }
	if($month=='May'){ $month='05'; }
	if($month=='Jun'){ $month='06'; }
	if($month=='Jul'){ $month='07'; }
	if($month=='Aug'){ $month='08'; }
	if($month=='Sep'){ $month='09'; }
	if($month=='Oct'){ $month='10'; }
	if($month=='Nov'){ $month='11'; }
	if($month=='Dec'){ $month='12'; }

	$year_concade= $year.'-'.$month.'-'.$day;

	return  $year_concade;
}

// get duplicate entries from #__comprofiler
function proGetDuplicateEntriesComprofiler()
{
	$check_result = "OK";

	$query = "select user_id,firstname,middlename,lastname,cb_prodep_wemail,cb_prodep_hemail
		from #__comprofiler where proteonkey in (select proteonkey from
    	(select count(proteonkey) c,proteonkey from #__comprofiler group by proteonkey order by c desc) as junk
    	where c>1) and proteonkey != ''";
	$result = proRunQuery($query, "SELECTOBJ");
	
	if (count($result) == 0) {
		// no duplicate entries
		$check_result = "OK";
	} else {
		$entries_tbl = "<table id='synctable'>\n";

		/* fetch object array */
		foreach ($result as $obj) {
			$entries_tbl .= "<tr>";
			$entries_tbl .= "<td>".$obj->user_id."</td>";
			$entries_tbl .= "<td>".$obj->firstname."</td>";
			$entries_tbl .= "<td>".$obj->middlename."</td>";
			$entries_tbl .= "<td>".$obj->lastname."</td>";
			$entries_tbl .= "<td>".$obj->cb_prodep_wemail."</td>";
			$entries_tbl .= "</tr>\n";
		}

		$entries_tbl .= "</table>\n";

		$check_result = "NOTOK|Duplicate entries: ".$result->num_rows."|".$entries_tbl;
	}
	
	return $check_result;
}

// synchronize jnews tables with #__users
//   1) delete duplicate entries (subscribers with the same user id and multiple entries
//      in jnews_subscribers table) leaving only the latest entry added (latest subscribe_date)
//   2) delete jnews_listssubscriber entries whose subscribers ids are not in jnews_subscribers

function proSynchronizeJnewsSubscribers()
{
	$check_result = "OK";

	$query = "SELECT user_id, max(subscribe_date) AS subscribe_date FROM #__jnews_subscribers
		WHERE user_id IN (SELECT user_id FROM #__jnews_subscribers
			WHERE user_id > 0
			GROUP BY user_id HAVING count(user_id) > 1)
		GROUP BY user_id";
	$result = proRunQuery($query, "SELECTOBJ");
	
	if (count($result) == 0) {
		// no duplicate entries
		$check_result = "OK";
	} else {
		/* fetch object array */
		foreach ($result as $obj) {
			$query = "DELETE from #__jnews_subscribers WHERE user_id=".$obj->user_id.
				" AND subscribe_date != '".$obj->subscribe_date."'";
			proRunQuery($query, "UPDATE");
		}
	}
	
	// to keep #__jnews_listssubscribers in sync with #__jnews_subscribers, delete entries
	// .. from listssubscribers where subscriber_id is not in jnews_subscribers table
	$query = "DELETE from #__jnews_listssubscribers where subscriber_id not in
		(select distinct id from #__jnews_subscribers)";

	proRunQuery($query, "UPDATE");
	
	return $check_result;
}

// run the given query
function proRunQuery($query, $queryType)
{
global $dbhandle;
global $jc;

	$result = false;
	$db_error = false;

	// connect and return handle to database
	$dbhandle->set_charset("utf8");
	$dbprefix = $jc->dbprefix;

	// replace #__ with dbprefix so that it runs from both joomla and cron
	$query = preg_replace("/#__/", $dbprefix, $query);	
	
	switch ($queryType) {
		case 'SELECTOBJ':
			if ($rows = $dbhandle->query($query)) {
				$result = array();

				while($obj = $rows->fetch_object()){
					$result[] = $obj;
				}
			} else {
				$db_error = true;
			}
			break;
		case 'SELECTASSOC':
				if ($rows = $dbhandle->query($query)) {
				$result = array();

				while($assoc_obj = $rows->fetch_assoc()){
					$result[] = $assoc_obj;
				}
			} else {
				$db_error = true;
			}
			break;
		case 'UPDATE':
			if ($dbhandle->query($query)) {
				// insert succeeded
				$result = true;
			} else {
				$db_error = true;
			}
			break;
	}
	
	if ($db_error) {
		proExit("NOTOK|".$dbhandle->errno . ":" . $dbhandle->error."|".$query);
	}
	
	return $result;
}

// exit with a message
function proExit($msg)
{
	echo $msg;
	flush();
	exit();
}

// setup db connection
function proGetDbConnection()
{
	$rrecord = array();
	global $dbhandle;
	global $jc;

	// connect and return handle to database
	$jc = new JConfig();
	$dbhandle = new mysqli($jc->host, $jc->user, $jc->password, $jc->db);
	// Connect. Required for sanitizing input with quote characters before saving to DB.
	$dblink = mysql_connect($jc->host, $jc->user, $jc->password) OR die(mysql_error());
	
	/* connected? */
	if (mysqli_connect_errno()) {
		$rrecord["error"] = true;
		$rrecord["errmsg"] = "Connection to DB failed: ".mysqli_connect_error();
	} else {
		$rrecord["error"] = false;
		$rrecord["result"] = $dbhandle;
		$rrecord["dblink"] = $dblink;
		$rrecord["dbprefix"] = $jc->dbprefix;
	}

	return $rrecord;
}

// check if chapter is PMIFrance
function isFranceChapter()
{
	$result = false;
	$jc = new JConfig();
	if ($jc->user == "pmifrance") { $result = true; }
	return $result;
}

// check if chapter is NYC
function isNYCChapter()
{
	$result = false;
	$jc = new JConfig();
	if (($jc->user == "pminyc") || ($jc->user == "newprod-pminyc")) { $result = true; }
	return $result;
}

function update_subscribe(){
    global $dbhandle;
    global $jc;

    // connect and return handle to database
    $dbhandle->set_charset("utf8");
    $dbprefix = $jc->dbprefix;

    $query  =   'UPDATE #__jnews_subscribers
                INNER JOIN #__users ON #__users.email = #__jnews_subscribers.email
                SET #__jnews_subscribers.user_id = #__users.id
                WHERE #__jnews_subscribers.user_id = 0';

    // replace #__ with dbprefix so that it runs from both joomla and cron
    $query = preg_replace("/#__/", $dbprefix, $query);

    if ($dbhandle->query($query)) {
        // insert succeeded
        $result = true;
    }
	

	
}

?>
