<?php

ini_set('memory_limit', '2G');

echo '<pre>';
require_once __DIR__ . '/vendor/autoload.php';
require_once 'connect.php';

$ts = microtime(true);

$urlExternal = "http://www.lolesports.com/en_US/";
$urlVideos = "http://api.lolesports.com/api/v2/videos";
$urlMatch = "http://api.lolesports.com/api/v2/highlanderMatchDetails?tournamentId=%s&matchId=%s";
$urlLeague = "http://api.lolesports.com/api/v1/leagues/%s";
$urlSchedule = "http://api.lolesports.com/api/v1/scheduleItems?leagueId=%s";

//Google API Client
$client = getClient();
$client->setUseBatch(true);
$service = new Google_Service_Calendar($client);

function getJson($filename, $url){
    set_time_limit(300);

    $file = __DIR__ . "/json/$filename.json";
    if(0 && file_exists($file)){
//    if(file_exists($file)){
        $fileContent = file_get_contents($file);
    }
    else{
        $fileContent = file_get_contents($url);
        file_put_contents($file, $fileContent);
    }
    return json_decode($fileContent, true);
}

function getTeam($position, $match, $tournament, $matches, $teams){
    $input = $match['input'][$position-1];
    if(isset($input['roster']) && isset($tournament['rosters'][$input['roster']]['team']) && isset($teams[$tournament['rosters'][$input['roster']]['team']])){
        return $teams[$tournament['rosters'][$input['roster']]['team']];
    }
    else if(isset($input['roster']) && isset($tournament['rosters'][$input['roster']])){
        return [
            'acronym' => $tournament['rosters'][$input['roster']]['name'],
            'name' => $tournament['rosters'][$input['roster']]['name'],
        ];
    }
    else if(isset($input['match'])){
        return [
            'acronym' => "Winner of {$matches[$input['match']]['name']}",
            'name' => "Winner of {$matches[$input['match']]['name']}"
        ];
    }
//    else if(isset($input['breakpoint'])){
//        return [
//            'name' => $tournament['breakpoints'][$input['breakpoint']]['name'],
//        ];
//    }
    else{
        return ['name' => '???', 'acronym' => '???'];
    }
}

function getMatchType($bracket, $match){
    //Vars
    $translate = [
        'single_elim' => 'Single Elimination',
        'standard' => 'Best of 1',
    ];
    $default = [
        'maxGames' => 1,
        'label' => 'NO MATCH TYPE',
    ];

    //No type: return default
    if(isset($match['matchType'])){
        $mType = $match['matchType'];
    }
    else if(isset($bracket['matchType'])){
        $mType = $bracket['matchType'];
    }
    else if(isset($bracket['inheritableMatchScoringStrategy'])){
        $mType = $bracket['inheritableMatchScoringStrategy'];
    }
    else{
        return $default;
    }

    //Compute maximum number of games
    if($mType['identifier'] == 'best_of'){
        return [
            'maxGames' => $mType['options']['best_of'],
            'label' => 'Best of ' . $mType['options']['best_of'],
        ];
    }
    else if(isset($translate[$mType['identifier']])){
        $default['label'] =  $translate[$mType['identifier']];
    }
    else{
        //Do nothing
//        print_r($mType);
//        print_r($match);
    }

    return $default;
}

function batchExecute($batch){
    $shouldReUpdate = false;
    foreach($batch->execute() as $line){
        if($line instanceof Google_Service_Exception){
            if($line->getCode() == 403){
                die();
            }
            else if($line->getCode() == 409){
                //We must re-update
                $shouldReUpdate = true;
            }
            else{
                echo $line->getMessage() . "\n";
            }
        }
    }
    return $shouldReUpdate;
}

//Get Videos
$allVideos = collect(getJson('videos', $urlVideos)['videos'])->groupBy('game')->toArray();

//Run for all Leagues
$ignoreOlderThandMonthes = 1;
$idLeagueStart = 1;
$idLeagueEnd = 60;
for($idLeague=$idLeagueStart; $idLeague<=$idLeagueEnd; $idLeague++){
    //Get files
    $dataLeague = getJson("league$idLeague", sprintf($urlLeague, $idLeague));
    $dataSchedule = getJson("schedule$idLeague", sprintf($urlSchedule, $idLeague));

    //Prepare data into arrays
    $league = $dataLeague['leagues'][0];
    $teams = collect($dataSchedule['teams'])->keyBy('id')->toArray();
    $tournaments = collect($dataSchedule['highlanderTournaments'])->keyBy('id')->toArray();
    $scheduleItems = collect($dataSchedule['scheduleItems'])->sortBy('scheduledTime', SORT_REGULAR, true)->toArray();
    $brackets = [];
    $matches = [];
    foreach($tournaments as $t){
        foreach($t['brackets'] as $b){
            $brackets[$b['id']] = $b;
            foreach($b['matches'] as $m){
                $matches[$m['id']] = $m;
            }
        }
    }

    //League short name
    $tournamentShort = explode('-', strtoupper($league['slug']));
    $tournamentShort = isset($tournamentShort[1]) && in_array($tournamentShort[1], ['CS', 'LCS', 'STAR']) ? implode(' ', $tournamentShort) : $tournamentShort[0];

    //Read matches
    $events = [];
    foreach($scheduleItems as $scheduleItem){

        if( ! isset($scheduleItem['match'])){
            //ignore non matches
            continue;
        }

        //Read data
        $tournament = $tournaments[$scheduleItem['tournament']];
        $bracket = $brackets[$scheduleItem['bracket']];
        $match = $matches[$scheduleItem['match']];
        $matchType = getMatchType($bracket, $match);
        $tags = $scheduleItem['tags'];
        $team1 = getTeam(1, $match, $tournament, $matches, $teams);
        $team2 = getTeam(2, $match, $tournament, $matches, $teams);
        $dateStart = (new \Carbon\Carbon($scheduleItem['scheduledTime']))->toAtomString();
        $dateEnd = (new \Carbon\Carbon($scheduleItem['scheduledTime']))->addHours($matchType['maxGames'])->toAtomString();
        $uid = str_replace('-','v', $match['id']);

        //If the match is at most a month old, we will no longer update it
        if($ignoreOlderThandMonthes>0 && (new \Carbon\Carbon($dateStart))->addMonths($ignoreOlderThandMonthes)->isPast()){
            //Since we are sorted by date, we can exit the loop right away
            break;
        }

        //
        if(count($match['input']) != 2) {
            throw new \Exception('Wrong number of teams for match');
        }

        //Block label
        $labelPrefix = '';
        if( isset($scheduleItem['tags']['blockLabel']) && ! is_numeric($scheduleItem['tags']['blockLabel'])){
            $labelPrefix = ucwords($scheduleItem['tags']['blockLabel'] . ': ');
        }

        //If our event already exists
        if(isset($events[$uid])){
            $uid.= 'vvv' . (new \Carbon\Carbon($scheduleItem['scheduledTime']))->format('Ymdhis');
        }

        //For matches which are more than 1 week old, we update the VODs
        $videos = [];
        if((new \Carbon\Carbon($dateStart))->addWeek()->isPast()){
            foreach($match['games'] as $game){
                if(isset($allVideos[$game['id']])){
                    $string = $game['name'].': ';
                    $gameVideos = [];
                    foreach($allVideos[$game['id']] as $video){
                        $gameVideos[$video['locale']] = "<a href='{$video['source']}'>".$video['locale']."</a>";
                    }
                    ksort($gameVideos); //sorting to have language in same orders
                    $videos[$game['name']] = $string . implode(' / ', $gameVideos);
                }
            }
            ksort($videos); //sorting to get games in the correct order
        }

        //Work on Summary
        $description = implode('<br/>', [
            $tournament['description'],
            $matchType['label'],
            @ucwords(trim("{$tags['blockPrefix']} {$tags['blockLabel']}")),// {$tags['subBlockPrefix']} {$tags['subBlockLabel']}")),
            "{$team1['name']} vs {$team2['name']}",
            count($videos) ? '<br/><b>VODS:</b><br/>' . implode('<br/>', $videos) : '',
        ]);

        //Register in our Events array
        $events[$uid] = new Google_Service_Calendar_Event(array(
            'id' => $uid,
            'summary' => "[$tournamentShort] $labelPrefix{$team1['acronym']} vs {$team2['acronym']}",
            'description' => $description,
            'start' => array(
                'dateTime' => $dateStart,
            ),
            'end' => array(
                'dateTime' => $dateEnd,
            ),

        ));

        //Summary line for debug
        echo "{$league['id']} - {$events[$uid]['start']['dateTime']}: {$events[$uid]['summary']}\n";// \t\t\t$uid\n$description\n\n";
    }

    //DEBUG: Uncomment to prevent updating calendar
    //    continue;

    //Exit if nothing to update
    if( ! count($events)){
        continue;
    }


    //EVENTS CLEAN UP

    //Looking for date to start looking for
    $earliestDate = \Carbon\Carbon::today()->subMonths($ignoreOlderThandMonthes);

    //We will look for all events from $earliestDate to the future
    $client->setUseBatch(false);
    $foundUids = [];
    $params = [
        'q' => "[$tournamentShort]",
        'timeMin' => $earliestDate->toAtomString(),
    ];
    do{
        $results = $service->events->listEvents(CALENDAR_ID, $params);
        foreach($results->getItems() as $event){
            $foundUids[] = $event->id;
        }
        $pageToken = $results->getNextPageToken();
        if($pageToken){
            $params = ['pageToken' => $pageToken];
        }
        else{
            break;
        }
    }while(true);
    $client->setUseBatch(true);

    //Diff to determine what is to delete
    $toDelete = array_diff($foundUids, array_keys($events));
    if(count($toDelete)){
        $batch = new Google_Http_Batch($client);
        foreach($toDelete as $uid){
            $batch->add($service->events->delete(CALENDAR_ID, $uid));
        }
        $batch->execute();
    }

    //Batch insert / update
    $batch = new Google_Http_Batch($client);
    foreach($events as $event){
        if(in_array($event['id'], $foundUids)){
            $batch->add($service->events->update(CALENDAR_ID, $event['id'], $event));
        }
        else {
            $batch->add($service->events->insert(CALENDAR_ID, $event));
        }
    }

    //Execute and display errors
    $shouldReUpdate = batchExecute($batch);

    //Batch update again, if necessary
    if($shouldReUpdate){
        $batch = new Google_Http_Batch($client);
        foreach($events as $event){
            $batch->add($service->events->update(CALENDAR_ID, $event['id'], $event));
        }
        batchExecute($batch);
    }

    sleep(5);
}

file_put_contents('log.txt', date('Ymd-his') .  ' completed in ' . round(microtime(true)-$ts) . "s\n", FILE_APPEND);
