<?php

class TalksController extends BaseTalkController
{
    public function getAction($request, $db)
    {
        $this->setDbAndRequest($db, $request);
        $talk_id = $this->getItemId($request);

        $verbose = $this->getVerbosity($request);

        $talk = $this->getTalkById($request, $db, $talk_id, $verbose);
        $collection = new TalkModelCollection([$talk], 1);

        return $collection->getOutputView($request, $verbose);

    }

    public function getTalkComments($request, $db)
    {
        $this->setDbAndRequest($db, $request);
        $talk_id = $this->getItemId($request);
        $verbose = $this->getVerbosity($this->request);

        // pagination settings
        $start          = $this->getStart($this->request);
        $resultsperpage = $this->getResultsPerPage($this->request);

        $comment_mapper = $this->getMapper('talkcomment');

        return $comment_mapper->getCommentsByTalkId($talk_id, $resultsperpage, $start, $verbose);
    }

    public function getTalkStarred($request, $db)
    {
        $this->setDbAndRequest($db, $request);
        $talk_id = $this->getItemId($request);
        $mapper = $this->getMapper('talk');

        return $mapper->getUserStarred($talk_id, $this->request->user_id);
    }

    public function getTalkByKeyWord($request, $db)
    {
        if (!isset($request->parameters['title'])) {
            throw new Exception('Generic talks listing not supported', 405);
        }

        $this->setDbAndRequest($db, $request);

        $keyword = filter_var(
            $request->parameters['title'],
            FILTER_SANITIZE_STRING,
            FILTER_FLAG_NO_ENCODE_QUOTES
        );

        $verbose = $this->getVerbosity($this->request);

        $start          = $this->getStart($this->request);
        $resultsperpage = $this->getResultsPerPage($this->request);

        $mapper = $this->getMapper('talk');
        $talks = $mapper->getTalksByTitleSearch($keyword, $resultsperpage, $start);

        return $talks->getOutputView($this->request, $verbose);
    }

    public function postAction($request, $db)
    {
        $this->checkLoggedIn($request);
        $talk_id = $this->getItemId($request);

        // Retrieve the talk. It if doesn't exist, then 404 with talk not found
        $talk = $this->getTalkById($request, $db, $talk_id);

        if (isset($request->url_elements[4])) {
            switch ($request->url_elements[4]) {
                case "comments":
                    $comment = $request->getParameter('comment');
                    if (empty($comment)) {
                        throw new Exception('The field "comment" is required', 400);
                    }

                    $rating = $request->getParameter('rating');
                    if (empty($rating)) {
                        throw new Exception('The field "rating" is required', 400);
                    }

                    $private = ($request->getParameter('private') ? 1 : 0);

                    // Get the API key reference to save against the comment
                    $oauth_model   = $request->getOauthModel($db);
                    $consumer_name = $oauth_model->getConsumerName($request->getAccessToken());

                    $talk_mapper    = $this->getTalkMapper($db, $request);
                    $comment_mapper = $this->getMapper('talkcomment', $db, $request);

                    $data['user_id'] = $request->user_id;
                    $data['talk_id'] = $talk_id;
                    $data['comment'] = $comment;
                    $data['rating']  = $rating;
                    $data['private'] = $private;
                    $data['source']  = $consumer_name;

                    try {
                        // run it by akismet if we have it
                        if (isset($this->config['akismet']['apiKey'], $this->config['akismet']['blog'])) {
                            $spamCheckService = new SpamCheckService(
                                $this->config['akismet']['apiKey'],
                                $this->config['akismet']['blog']
                            );
                            $isValid          = $spamCheckService->isCommentAcceptable(
                                $data,
                                $request->getClientIP(),
                                $request->getClientUserAgent()
                            );
                            if (! $isValid) {
                                throw new Exception("Comment failed spam check", 400);
                            }
                        }

                        // should rating be allowed?
                        if ($comment_mapper->hasUserRatedThisTalk($data['user_id'], $data['talk_id'])) {
                            $data['rating'] = 0;
                        }
                        if ($talk_mapper->isUserASpeakerOnTalk($data['talk_id'], $data['user_id'])) {
                            // speakers cannot cannot rate their own talk
                            $data['rating'] = 0;
                        }

                        $new_id = $comment_mapper->save($data);
                    } catch (Exception $e) {
                        // just throw this again but with a 400 status code
                        throw new Exception($e->getMessage(), 400);
                    }

                    if ($new_id) {
                        $comment    = $comment_mapper->getCommentById($new_id);
                        $speakers   = $talk_mapper->getSpeakerEmailsByTalkId($talk_id);
                        $recipients = array();

                        foreach ($speakers as $person) {
                            if ($request->user_id == $person['ID']) {
                                continue;
                            }

                            $recipients[] = $person['email'];
                        }

                        $emailService = $this->getTalkCommentEmailService($this->config, $recipients, $talk, $comment);
                        $emailService->sendEmail();
                        $uri = $request->base . '/' . $request->version . '/talk_comments/' . $new_id;

                        $view = $request->getView();
                        $view->setHeader('Location', $uri);
                        $view->setResponseCode(201);
                        return;
                    } else {
                        throw new Exception("The comment could not be stored", 400);
                    }
                    break;
                case 'starred':
                    // the body of this request is completely irrelevant
                    // The logged in user *is* attending the talk.  Use DELETE to unattend
                    $talk_mapper = new TalkMapper($db, $request);
                    $talk_mapper->setUserStarred($talk_id, $request->user_id);

                    $view = $request->getView();
                    $view->setHeader('Location', $request->base . $request->path_info);
                    $view->setResponseCode(201);
                    return;
                default:
                    throw new Exception("Operation not supported, sorry", 404);
            }
        } else {
            throw new Exception("method not supported - sorry");
        }
    }

    public function deleteAction($request, $db)
    {
        $this->checkLoggedIn($request);
        if (isset($request->url_elements[4])) {
            switch ($request->url_elements[4]) {
                case 'starred':
                    $talk_id     = $this->getItemId($request);
                    $talk_mapper = new TalkMapper($db, $request);
                    $talk_mapper->setUserNonStarred($talk_id, $request->user_id);

                    $view = $request->getView();
                    $view->setHeader('Location', $request->base . $request->path_info);
                    $view->setResponseCode(200);
                    return;

                default:
                    throw new Exception("Operation not supported, sorry", 404);
            }
        } else {
            // delete the talk
            $talk_id     = $this->getItemId($request);
            $talk_mapper = new TalkMapper($db, $request);

            // note: use the mapper's getTalkById as we don't want to throw a not found exception
            $talk = $talk_mapper->getTalkById($talk_id);
            if (false === $talk) {
                // talk isn't there so it's as good as deleted
                $view = $request->getView();
                $view->setHeader('Content-Length', 0);
                $view->setResponseCode(204);
                return;
            }

            $is_admin = $talk_mapper->thisUserHasAdminOn($talk_id);
            if (! $is_admin) {
                throw new Exception("You do not have permission to do that", 400);
            }

            $talk_mapper->delete($talk_id);
            $view = $request->getView();
            $view->setHeader('Content-Length', 0);
            $view->setResponseCode(204);
            return;
        }
    }

    /**
     * Add a track to a talk by POSTing to /talks/123/tracks with the `track_uri`
     * in the body
     *
     * @param PDO $db
     * @param Request $request
     */
    public function addTrackToTalk(Request $request, PDO $db)
    {
        try {
            $this->checkLoggedIn($request);
        } catch (Exception $e) {
            // throw again with a 400 status, This should be removed for consistency
            // but will break backwards compatibility
            throw new Exception($e->getMessage(), 400);
        }

        $talk_mapper = new TalkMapper($db, $request);
        $talk = $this->getTalkById($request, $db);
        $talk_id = $talk->ID;

        $is_admin = $talk_mapper->thisUserHasAdminOn($talk_id);
        $is_speaker = $talk_mapper->isUserASpeakerOnTalk($talk_id, $request->user_id);
        if (!($is_admin || $is_speaker)) {
            throw new Exception("You do not have permission to add this talk to a track", 400);
        }

        $track_uri = $request->getParameter("track_uri");
        $pattern ='@/' . $request->version . '/tracks/([\d]+)$@';
        if (!preg_match($pattern, $track_uri, $matches)) {
            throw new Exception('Invalid track_uri', 400);
        }
        $track_id = $matches[1];

        // is this track on this event?
        $event_mapper = new EventMapper($db, $request);
        $track_events = $event_mapper->getEventByTrackId($track_id, true, false, false);
        if (!$track_events || !$track_events[0]['ID']) {
            throw new Exception("Associated event not found", 400);
        }
        $track_event_id = $track_events[0]['ID'];
        if ($talk->event_id != $track_event_id) {
            throw new Exception("This talk cannot be added to this track", 400);
        }

        // add talk to track
        $talk_mapper->addTalkToTrack($talk_id, $track_id);

        $uri = $request->base . '/' . $request->version . '/talks/' . $talk_id;

        $view = $request->getView();
        $view->setHeader('Location', $uri);
        $view->setResponseCode(201);
    }

    /**
     * Remove a track from a talk by DELETEing to /talks/123/tracks/456
     *
     * @param PDO $db
     * @param Request $request
     */
    public function removeTrackFromTalk(Request $request, PDO $db)
    {
        try {
            $this->checkLoggedIn($request);
        } catch (Exception $e) {
            // throw again with a 400 status, This should be removed for consistency
            // but will break backwards compatibility
            throw new Exception($e->getMessage(), 400);
        }

        $track_id = $request->url_elements[5];

        $talk_mapper = new TalkMapper($db, $request);
        $talk = $this->getTalkById($request, $db);
        $talk_id = $talk->ID;

        $is_admin = $talk_mapper->thisUserHasAdminOn($talk_id);
        $is_speaker = $talk_mapper->isUserASpeakerOnTalk($talk_id, $request->user_id);
        if (!($is_admin || $is_speaker)) {
            throw new Exception("You do not have permission to remove this talk from this track", 400);
        }

        // is this track on this event?
        $event_mapper = new EventMapper($db, $request);
        $track_events = $event_mapper->getEventByTrackId($track_id, true, false, false);
        if (!$track_events || !$track_events[0]['ID']) {
            throw new Exception("Associated event not found", 400);
        }
        $track_event_id = $track_events[0]['ID'];
        if ($talk->event_id != $track_event_id) {
            throw new Exception("This talk cannot be added to this track", 400);
        }

        // delete track from talk
        $talk_mapper->removeTrackFromTalk($talk_id, $track_id);

        $uri = $request->base . '/' . $request->version . '/talks/' . $talk_id;

        $view = $request->getView();
        $view->setHeader('Location', $uri);
        $view->setResponseCode(204);
    }

    /**
     * Create a talk
     *
     * This method creates a new talk after being called via the URL
     * "/events/[eventId]/talks"
     *
     * @param Request $request
     * @param PDO     $db
     *
     * @throws Exception
     * @return void
     */
    public function createTalkAction(Request $request, PDO $db)
    {
        $this->checkLoggedIn($request);
        $event_id = $this->getItemId($request);
        if (empty($event_id)) {
            throw new Exception(
                "POST expects a talk representation sent to a specific event URL",
                400
            );
        }

        $event_mapper = new EventMapper($db, $request);
        $talk_mapper = new TalkMapper($db, $request);

        $is_admin = $event_mapper->thisUserHasAdminOn($event_id);
        if (!$is_admin) {
            throw new Exception("You do not have permission to add talks to this event", 400);
        }

        // retrieve the talk data from the request
        $talk = $this->getTalkDataFromRequest($db, $request, $event_id);
        $talk['event_id'] = $event_id;

        // create the talk
        $new_id = $talk_mapper->createTalk($talk);

        // Update the cache count for the number of talks at this event
        $event_mapper->cacheTalkCount($event_id);

        $uri = $request->base . '/' . $request->version . '/talks/' . $new_id;
        $request->getView()->setResponseCode(201);
        $request->getView()->setHeader('Location', $uri);

        $new_talk = $this->getTalkById($request, $db, $new_id);
        $collection = new TalkModelCollection([$new_talk], 1);
        $list = $collection->getOutputView($request);

        return $list;
    }

    /**
     * Edit a talk
     *
     * Edit talk after being called via the URL "/talks/[talkId]"
     *
     * @param Request $request
     * @param PDO     $db
     *
     * @throws Exception
     * @return void
     */
    public function editTalk(Request $request, PDO $db)
    {
        $this->checkLoggedIn($request);

        $talk_id = $this->getItemId($request);

        $talk_mapper = new TalkMapper($db, $request);

        $talk = $this->getTalkById($request, $db);

        $is_admin = $talk_mapper->thisUserHasAdminOn($talk_id);
        $is_speaker = $talk_mapper->isUserASpeakerOnTalk($talk_id, $request->user_id);
        if (!($is_admin || $is_speaker)) {
            throw new Exception("You do not have permission to update this talk", 403);
        }

        // retrieve the talk data from the request
        $data = $this->getTalkDataFromRequest($db, $request, $talk->event_id);

        // edit the talk
        $talk_mapper->editTalk($data, $talk_id);

        $view = $request->getView();
        $view->setHeader('Location', $request->base . $request->path_info);
        $view->setResponseCode(204);
    }

    /**
     * Read the talk fields from the request body and validate and return an
     * array ready for saving to the database.
     *
     * This is common for createTalk() and editTalk().
     *
     * @param  PDO     $db
     * @param  Request $request
     * @param  int     $event_id
     *
     * @return array
     */
    protected function getTalkDataFromRequest(PDO $db, Request $request, $event_id)
    {
        // get the event so we can get the timezone info & it
        $event_mapper = new EventMapper($db, $request);
        $list = $event_mapper->getEventById($event_id, true);
        if (count($list['events']) == 0) {
            throw new Exception('Event not found', 404);
        }
        $event = $list['events'][0];

        $talk['title'] = filter_var(
            $request->getParameter('talk_title'),
            FILTER_SANITIZE_STRING,
            FILTER_FLAG_NO_ENCODE_QUOTES
        );
        if (empty($talk['title'])) {
            throw new Exception("The talk title field is required", 400);
        }

        $talk['description'] = filter_var(
            $request->getParameter('talk_description'),
            FILTER_SANITIZE_STRING,
            FILTER_FLAG_NO_ENCODE_QUOTES
        );
        if (empty($talk['description'])) {
            throw new Exception("The talk description field is required", 400);
        }

        $talk['type'] = filter_var(
            $request->getParameter('type', 'Talk'),
            FILTER_SANITIZE_STRING,
            FILTER_FLAG_NO_ENCODE_QUOTES
        );

        $talk_type_mapper = new TalkTypeMapper($db, $request);
        $talk_types = $talk_type_mapper->getTalkTypesLookupList();
        if (! array_key_exists($talk['type'], $talk_types)) {
            throw new Exception("The type '{$talk['type']}' is unknown", 400);
        }
        $talk['type_id'] = $talk_types[$talk['type']];

        $start_date = filter_var(
            $request->getParameter('start_date'),
            FILTER_SANITIZE_STRING,
            FILTER_FLAG_NO_ENCODE_QUOTES
        );
        if (empty($start_date)) {
            throw new Exception("Please give the date and time of the talk", 400);
        }
        $tz = new DateTimeZone($event['tz_continent'] . '/' . $event['tz_place']);
        $talk['date'] = (new DateTime($start_date, $tz))->format('U');

        $event_start_date = (new DateTime($event['start_date']))->format('U');
        $event_end_date = (new DateTime($event['end_date']))->add(new DateInterval('P1D'))->format('U');
        if ($talk['date'] < $event_start_date || $talk['date'] >= $event_end_date) {
            throw new Exception("The talk must be held between the start and end date of the event", 400);
        }

        $talk['language'] = filter_var(
            $request->getParameter('language'),
            FILTER_SANITIZE_STRING,
            FILTER_FLAG_NO_ENCODE_QUOTES
        );
        if (empty($talk['language'])) {
            // default to UK English
            $talk['language'] = 'English - UK';
        }
        // When the language doesn't exist, the talk will not be found
        $language_mapper = new LanguageMapper($db, $request);
        if (! $language_mapper->isLanguageValid($talk['language'])) {
            throw new Exception("The language '{$talk['type']}' is unknown", 400);
        }

        $talk['duration'] = filter_var(
            $request->getParameter('duration'),
            FILTER_SANITIZE_NUMBER_INT
        );
        if (empty($talk['duration'])) {
            $talk['duration'] = 60;
        }

        $talk['slides_link'] = filter_var(
            $request->getParameter('slides_link'),
            FILTER_SANITIZE_URL
        );

        $talk['speakers'] = array_map(
            function ($speaker) {
                $speaker = filter_var($speaker, FILTER_SANITIZE_STRING, FILTER_FLAG_NO_ENCODE_QUOTES);
                $speaker = trim($speaker);
                return $speaker;
            },
            (array) $request->getParameter('speakers')
        );

        return $talk;
    }

    public function getSpeakersForTalk(Request $request, PDO $db)
    {
        $talk_id = $this->getItemId($request);
        $talk = $this->getTalkById($request, $db, $talk_id);
        return $talk->speakers;
    }

    public function setSpeakerForTalk(Request $request, PDO $db)
    {
        $this->checkLoggedIn($request);

        $talk = $this->getTalkById($request, $db);
        $talk_id = $talk->ID;
        $talk_mapper = $this->getTalkMapper($request, $db);

        $event_id = $talk->event_id;
        $event_mapper = $this->getEventMapper($db, $request);
        $event = $event_mapper->getEventById($event_id);

        $user_id = $request->user_id;
        $user_mapper = $this->getUserMapper($db, $request);
        $user = $user_mapper->getUserById($user_id)['users'][0];

        $data = $this->getLinkUserDataFromRequest($request);

        if ($data['display_name'] === '' || $data['username'] === '') {
            throw new Exception("You must provide a display name and a username", 400);
        }

        //Get the speaker record based on the display name - check if this is already claimed,
        //otherwise ID becomes the claim_id

        $claim = $talk_mapper->getSpeakerFromTalk($talk_id, $data['display_name']);

        if ($claim === false) {
            throw new Exception("No speaker matching that name found", 422);
        }

        if ($claim['speaker_id'] != null && $claim['speaker_id'] != 0) {
            throw new Exception("Talk already claimed", 422);
        }

        $speaker_id = $user_mapper->getUserIdFromUsername($data['username']);
        if (! $speaker_id) {
            throw new Exception("Specified user not found", 404);
        }
        $speaker_name = $user_mapper->getUserById($speaker_id)['users'][0]['full_name'];

        $pending_talk_claim_mapper = $this->getPendingTalkClaimMapper($db, $request);
        $claim_exists = $pending_talk_claim_mapper->claimExists($talk_id, $speaker_id, $claim['ID']);
        if ($claim_exists === false) {
            //This is a new claim
            //Is the speaker this user?
            if ($data['username'] === $user['username']) {
                $pending_talk_claim_mapper->claimTalkAsSpeaker($talk_id, $user_id, $claim['ID']);
                //We need to send an email to the host asking for confirmation
                $recipients   = $event_mapper->getHostsEmailAddresses($event_id);
                $emailService = new TalkClaimEmailService($this->config, $recipients, $event, $talk);
                if (!defined('UNIT_TEST')) {
                    $emailService->sendEmail();
                }
            } elseif ($talk_mapper->thisUserHasAdminOn($talk_id)) {
                $pending_talk_claim_mapper->assignTalkAsHost($talk_id, $speaker_id, $claim['ID'], $user_id);
                //We need to send an email to the speaker asking for confirmation
                $recipients   = [$user_mapper->getEmailByUserId($speaker_id)];
                $username = $data['username'];
                $emailService = new TalkAssignEmailService($this->config, $recipients, $event, $talk, $username);
                if (!defined('UNIT_TEST')) {
                    $emailService->sendEmail();
                }
            } else {
                throw new Exception("You must be the speaker or event admin to link a user to a talk", 401);
            }
        } elseif ($claim_exists === PendingTalkClaimMapper::SPEAKER_CLAIM) {
            //The host needs to approve
            if ($talk_mapper->thisUserHasAdminOn($talk_id)) {
                $method = $this->getRequestParameter($request, 'action', 'approve');
                $recipients   = [$user_mapper->getEmailByUserId($speaker_id)];

                $success = $pending_talk_claim_mapper->approveClaimAsHost($talk_id, $speaker_id, $claim['ID'])
                           && $talk_mapper->assignTalkToSpeaker($talk_id, $claim['ID'], $speaker_id, $speaker_name);

                $emailService = new TalkClaimApprovedEmailService($this->config, $recipients, $event, $talk);

                if (!$success) {
                    throw new Exception("There was a problem assigning the talk", 500);
                }

                if (!defined('UNIT_TEST')) {
                    $emailService->sendEmail();
                }
            } else {
                if ($speaker_id == $request->getUserId()) {
                    throw new Exception("You already have a pending claim for this talk. " .
                        "Please wait for an event admin to approve your claim.", 401);
                }
                throw new Exception("You must be an event admin to approve this claim", 401);
            }
        } elseif ($claim_exists === PendingTalkClaimMapper::HOST_ASSIGN) {
            //The speaker needs to approve
            if ($data['username'] === $user['username']) {
                if ($pending_talk_claim_mapper->approveAssignmentAsSpeaker($talk_id, $user_id, $claim['ID'])) {
                    if (! $talk_mapper->assignTalkToSpeaker($talk_id, $claim['ID'], $speaker_id, $speaker_name)) {
                        throw new Exception("There was a problem assigning the talk", 500);
                    }
                } else {
                    throw new Exception("There was a problem assigning the talk", 500);
                }
            } else {
                throw new Exception("You must be the talk speaker to approve this assignment", 401);
            }
        }

        $view = $request->getView();
        $view->setHeader('Location', $request->base . $request->path_info);
        $view->setResponseCode(204);
    }

    private function getLinkUserDataFromRequest(Request $request)
    {
        $talk = [];
        $talk['display_name'] = trim($request->getParameter('display_name', ''));
        $talk['username'] = trim($request->getParameter('username', ''));
        return $talk;
    }

    public function setPendingTalkClaimMapper(PendingTalkClaimMapper $pending_talk_claim_mapper)
    {
        $this->pending_talk_claim_mapper = $pending_talk_claim_mapper;
    }

    public function getPendingTalkClaimMapper($db, $request)
    {
        if (! isset($this->pending_talk_claim_mapper)) {
            $this->pending_talk_claim_mapper = new PendingTalkClaimMapper($db, $request);
        }

        return $this->pending_talk_claim_mapper;
    }

    public function removeApprovedSpeakerFromTalk(Request $request, PDO $db)
    {
        $this->checkLoggedIn($request);
        $talk_id = $this->getItemId($request);
        $speaker_id = $request->url_elements[5];

        $talk_mapper = new TalkMapper($db, $request);
        $talk = $this->getTalkById($talk_id);

        $speaker = $talk_mapper->isUserASpeakerOnTalk($talk_id, $speaker_id);
        if (!$speaker) {
            throw new Exception("Provided user is not a speaker on this talk", 404);
        }

        $is_admin = $talk_mapper->thisUserHasAdminOn($talk_id);
        $is_speaker = $talk_mapper->isUserASpeakerOnTalk($talk_id, $request->user_id);
        if (!($is_admin || $is_speaker)) {
            throw new Exception("You do not have permission to remove this speaker from this talk", 403);
        }

        // delete speaker from talk
        $talk_mapper->removeApprovedSpeakerFromTalk($talk_id, $speaker_id);

        $uri = $request->base . '/' . $request->version . '/talks/' . $talk_id;

        $view = $request->getView();
        $view->setHeader('Location', $uri);
        $view->setResponseCode(204);
    }

    public function getTalkCommentEmailService($config, $recipients, $talk, $comment)
    {
        return new TalkCommentEmailService($config, $recipients, $talk, $comment);
    }

    public function removeSpeakerForTalk(Request $request, PDO $db)
    {
        $this->checkLoggedIn($request);
        $talk = $this->getTalkById($request, $db);
        $talk_mapper = $this->getTalkMapper($request, $db);
        $talk_id = $talk->ID;

        $event_id = $talk->event_id;
        $event_mapper = $this->getEventMapper($db, $request);
        $event = $event_mapper->getEventById($event_id);

        $is_admin = $talk_mapper->thisUserHasAdminOn($talk_id);
        if (!($is_admin)) {
            throw new Exception("You do not have permission to reject the speaker claim on this talk", 403);
        }

        $data = $this->getLinkUserDataFromRequest($request);

        $user_mapper = $this->getUserMapper($db, $request);
        $speaker_id = $user_mapper->getUserIdFromUsername($data['username']);
        if (! $speaker_id) {
            throw new Exception("Specified user not found", 404);
        }

        $claim = $talk_mapper->getSpeakerFromTalk($talk_id, $data['display_name']);

        if ($claim === false) {
            throw new Exception("No speaker matching that name found", 422);
        }

        if ($claim['speaker_id'] != null && $claim['speaker_id'] != 0) {
            throw new Exception("Talk already claimed", 422);
        }

        if ($data['display_name'] === '' || $data['username'] === '') {
            throw new Exception("You must provide a display name and a username", 400);
        }

        $pending_talk_claim_mapper = $this->getPendingTalkClaimMapper($db, $request);
        $claim_exists = $pending_talk_claim_mapper->claimExists($talk_id, $speaker_id, $claim['ID']);

        if ($claim_exists !== PendingTalkClaimMapper::SPEAKER_CLAIM) {
            throw new Exception("There was a problem with the claim", 500);
        }
        $method = $this->getRequestParameter($request, 'action', 'approve');
        $recipients   = [$user_mapper->getEmailByUserId($speaker_id)];


        $success = $pending_talk_claim_mapper->rejectClaimAsHost($talk_id, $speaker_id, $claim['ID']);

        if (!$success) {
            throw new Exception("There was a problem assigning the talk", 500);
        }

        $emailService = new TalkClaimRejectedEmailService($this->config, $recipients, $event, $talk);
        if (!defined('UNIT_TEST')) {
            $emailService->sendEmail();
        }

        $uri = $request->base . '/' . $request->version . '/talks/' . $talk_id;

        $view = $request->getView();
        $view->setHeader('Location', $uri);
        $view->setResponseCode(204);

        return true;
    }
}
