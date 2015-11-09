<?php

/*
 * Copyright (C) 2015 Jacobi Carter and Chris Breneman
 *
 * This file is part of ClueBot NG.
 *
 * ClueBot NG is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * ClueBot NG is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with ClueBot NG.  If not, see <http://www.gnu.org/licenses/>.
 */
    class Process
    {
        public static function processEditThread($change)
        {
            $change[ 'edit_status' ] = 'not_reverted';
            if (!isset($s)) {
                $change[ 'edit_score' ] = 'N/A';
                $s = null;
            } else {
                $change[ 'edit_score' ] = $s;
            }
            if (!in_array('all', $change) || !isVandalism($change[ 'all' ], $s)) {
                Feed::bail($change, 'Below threshold', $s);

                return;
            }
            echo 'Is '.$change[ 'user' ].' whitelisted ?'."\n";
            if (Action::isWhitelisted($change[ 'user' ])) {
                Feed::bail($change, 'Whitelisted', $s);

                return;
            }
            echo 'No.'."\n";
            $reason = 'ANN scored at '.$s;
            $heuristic = '';
            $log = null;
            $diff = 'https://en.wikipedia.org/w/index.php'.
                '?title='.urlencode($change[ 'title' ]).
                '&diff='.urlencode($change[ 'revid' ]).
                '&oldid='.urlencode($change[ 'old_revid' ]);
            $report = '[['.str_replace('File:', ':File:', $change[ 'title' ]).']] was '
                .'['.$diff.' changed] by '
                .'[[Special:Contributions/'.$change[ 'user' ].'|'.$change[ 'user' ].']] '
                .'[[User:'.$change[ 'user' ].'|(u)]] '
                .'[[User talk:'.$change[ 'user' ].'|(t)]] '
                .$reason.' on '.gmdate('c');
            $oftVand = unserialize(file_get_contents('oftenvandalized.txt'));
            if (rand(1, 50) == 2) {
                foreach ($oftVand as $art => $artVands) {
                    foreach ($artVands as $key => $time) {
                        if ((time() - $time) > 2 * 24 * 60 * 60) {
                            unset($oftVand[ $art ][ $key ]);
                        }
                    }
                }
            }
            $oftVand[ $change[ 'title' ] ][] = time();
            if (count($oftVand[ $change[ 'title' ] ]) >= 30) {
                IRC::say('reportchannel', '!admin [['.$change['title'].']] has been vandalized '.(count($oftVand[ $change[ 'title' ] ])).' times in the last 2 days.');
            }
            file_put_contents('oftenvandalized.txt', serialize($oftVand));
            $ircreport = "\x0315[[\x0307".$change[ 'title' ]."\x0315]] by \"\x0303".$change[ 'user' ]."\x0315\" (\x0312 ".$change[ 'url' ]." \x0315) \x0306".$s."\x0315 (";
            $change['mysqlid'] = Db::detectedVandalism($change['user'], $change['title'], $heuristic, $reason, $change['url'], $change['old_revid'], $change['revid']);
            echo 'Should revert?'."\n";
            list($shouldRevert, $revertReason) = Action::shouldRevert($change);
            $change[ 'revert_reason' ] = $revertReason;
            if ($shouldRevert) {
                echo 'Yes.'."\n";
                $rbret = Action::doRevert($change);
                if ($rbret !== false) {
                    $change[ 'edit_status' ] = 'reverted';
                    RedisProxy::send( $change );
                    IRC::say('debugchannel', $ircreport."\x0304Reverted\x0315) (\x0313".$revertReason."\x0315) (\x0302".(microtime(true) - $change[ 'startTime' ])." \x0315s)");
                    Action::doWarn($change, $report);
                    Db::vandalismReverted($change['mysqlid']);
                    Feed::bail($change, $revertReason, $s, true);
                } else {
                    $change[ 'edit_status' ] = 'beaten';
                    $rv2 = API::$a->revisions($change[ 'title' ], 1);
                    if ($change[ 'user' ] != $rv2[ 0 ][ 'user' ]) {
                        RedisProxy::send( $change );
                        IRC::say('debugchannel', $ircreport."\x0303Not Reverted\x0315) (\x0313Beaten by ".$rv2[ 0 ][ 'user' ]."\x0315) (\x0302".(microtime(true) - $change[ 'startTime' ])." \x0315s)");
                        Db::vandalismRevertBeaten($change['mysqlid'], $change['title'], $rv2[ 0 ][ 'user' ], $change[ 'url' ]);
                        Feed::bail($change, 'Beaten by '.$rv2[ 0 ][ 'user' ], $s);
                    }
                }
            } else {
                RedisProxy::send( $change );
                IRC::say('debugchannel', $ircreport."\x0303Not Reverted\x0315) (\x0313".$revertReason."\x0315) (\x0302".(microtime(true) - $change[ 'startTime' ])." \x0315s)");
                Feed::bail($change, $revertReason, $s);
            }
        }
        public static function processEdit($change)
        {
            if (
                (time() - globals::$tfas) >= 1800
                and (preg_match('/\(\'\'\'\[\[([^|]*)\|more...\]\]\'\'\'\)/iU', API::$q->getpage('Wikipedia:Today\'s featured article/'.date('F j, Y')), $tfam))
            ) {
                globals::$tfas = time();
                globals::$tfa = $tfam[ 1 ];
            }
            if (config::$fork) {
                $pid = pcntl_fork();
                if ($pid != 0) {
                    echo 'Forked - '.$pid."\n";

                    return;
                }
            }
            $change = parseFeedData($change);
            $change[ 'justtitle' ] = $change[ 'title' ];
            if (in_array('namespace', $change) && $change[ 'namespace' ] != 'Main:') {
                $change[ 'title' ] = $change[ 'namespace' ].$change[ 'title' ];
            }
            self::processEditThread($change);
            if (config::$fork) {
                die();
            }
        }
    }
