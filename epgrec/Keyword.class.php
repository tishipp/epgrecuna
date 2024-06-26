<?php
include_once('config.php');
include_once( INSTALL_PATH . '/DBRecord.class.php' );
include_once( INSTALL_PATH . '/reclib.php' );
include_once( INSTALL_PATH . '/Reservation.class.php' );
include_once( INSTALL_PATH . '/Settings.class.php' );
include_once( INSTALL_PATH . '/recLog.inc.php' );


class Keyword extends DBRecord {
	protected $shm_id;
	protected $sem_key;
	protected $shm_cnt;

	public function __construct($property = null, $value = null, $row = null ){
		$this->shm_cnt = 0;
		try {
			parent::__construct(KEYWORD_TBL, $property, $value, $row );
		}
		catch( Exception $e ){
			throw $e;
		}
	}

	static public function search(  $keyword = '', 
									$use_regexp = false,
									$collate_ci = FALSE,
									$ena_title = FALSE,
									$ena_desc = FALSE,
									$typeGR = FALSE,
									$typeBS = FALSE,
									$typeCS = FALSE,
									$typeEX = FALSE,
									$category_id = 0,
									$channel_id = 0,
									$weekofday = 0x7f,
									$prgtime = 24,
									$period = 1,
									$sub_genre = 16,
									$first_genre = 1,
									$limit = 300 ){
		// ちょっと先を検索する
		$options = 'WHERE endtime>now()';

		if( $keyword != '' ){
			if( $ena_title ){
				$search_sorce = $ena_desc ? ' AND CONCAT(title, " ", description)' : ' AND title';
			}else
				$search_sorce = ' AND description';
			if( $use_regexp ){
				$options .= $search_sorce.' REGEXP "'.self::sql_escape( $keyword ).'"';
			}
			else {
				if( $collate_ci )
					$search_sorce .= ' COLLATE utf8_unicode_ci';
				foreach( explode( ' ', trim($keyword) ) as $key ){
					$k_len = strlen( $key );
					if( $k_len>1 && $key[0]==='-' ){
						$k_len--;
						$key = substr( $key, 1 );
						$not_add = TRUE;
					}else
						$not_add = FALSE;
					if( $k_len>2 && $key[0]==='"' && $key[$k_len-1]==='"' )
						$key = substr( $key, 1, $k_len-2 );
					if( strlen( $key ) > 0 ){
						$options .= $search_sorce;
						if( $not_add )
							$options .= ' NOT';
						$options .= ' LIKE "%'.self::sql_escape( $key ).'%"';
					}
				}
			}
		}

		if( $channel_id != 0 ){
			if( $typeGR ){
				// sub-channel間での移動対策
				$ch_obj   = new DBRecord( CHANNEL_TBL );
				$crec     = $ch_obj->fetch_array( 'id', $channel_id );
				$ch_list  = $ch_obj->distinct( 'id', 'WHERE channel LIKE "'.$crec[0]['channel'].'" AND skip=0' );
				$options .= ' AND channel_id IN ('.implode( ',', $ch_list ).')';
			}else
				$options .= ' AND channel_id='.$channel_id;
		}else{
			// TYPE
			if( self::$__settings === FALSE )
				self::$__settings = Settings::factory();
			$types   = '';
			$t_cnt   = 0;
			$t_total = 0;
			if( (int)self::$__settings->gr_tuners ){
				$t_total++;
				if( $typeGR ){
					$types .= '"GR"';
					$t_cnt++;
				}
			}
			if( (int)self::$__settings->bs_tuners ){
				$t_total++;
				if( $typeBS ){
					if( $types !== '' )
						$types .= ',';
					$types .= '"BS"';
					$t_cnt++;
				}
				if( (boolean)self::$__settings->cs_rec_flg ){
					$t_total++;
					if( $typeCS ){
						if( $types !== '' )
							$types .= ',';
						$types .= '"CS"';
						$t_cnt++;
					}
				}
			}
			if( EXTRA_TUNERS ){
				$t_total++;
				if( $typeEX ){
					if( $types !== '' )
						$types .= ',';
					$types .= '"EX"';
					$t_cnt++;
				}
			}
			if( 0 < $t_cnt ){
				// MySQLのJOINとかでやれるけど・・・
				$ch_que = 'WHERE skip=0';
				if( $t_cnt < $t_total )
					$ch_que .= ' AND type IN ('.$types.')';
				$chs = self::createRecords( CHANNEL_TBL, $ch_que );
				if( count($chs) ){
					$ch_ids = '';
					foreach( $chs as $ch ){
						if( $ch_ids !== '' )
							$ch_ids .= ',';
						$ch_ids .= (string)$ch->id;
					}
					$options .= ' AND channel_id IN ('.$ch_ids.')';
				}
			}
		}

		if( $category_id != 0 ){
			if( $first_genre ){
				$options .= ' AND category_id='.$category_id;
				if( $sub_genre<16 || ($category_id==15 && $sub_genre!=18) ){
					if( $category_id==7 && $sub_genre==3 )
						$options .= ' AND sub_genre IN (3,4)';
					else
						$options .= ' AND sub_genre='.$sub_genre;
				}
			}else{
				if( $category_id!=15 && $sub_genre==16 || $sub_genre==18 )
					$options .= ' AND (category_id='.$category_id.' OR genre2='.$category_id.' OR genre3='.$category_id.')';
				else
					$options .= ' AND ((category_id='.$category_id.' AND sub_genre='.$sub_genre.
								') OR (genre2='.$category_id.' AND sub_genre2='.$sub_genre.
								') OR (genre3='.$category_id.' AND sub_genre3='.$sub_genre.'))';
			}
		}

		if( $prgtime != 24 ){
			if( $prgtime+$period <= 24 ){
				$options .= self::setWeekofdays( $weekofday );
				$options .= ' AND time(starttime) BETWEEN cast("'.sprintf( '%02d:00:00', $prgtime ).'" as time) AND cast("'.sprintf( '%02d:59:59', $prgtime+($period-1) ).'" as time)';
			}else{
				$top_que = ' time(starttime) BETWEEN cast("00:00:00" as time) AND cast("'.sprintf( '%02d:59:59', ($period-(24-$prgtime)-1) ).'" as time) ';
				$btm_que = ' time(starttime) BETWEEN cast("'.sprintf( '%02d:00:00', $prgtime ).'" as time) AND cast("23:59:59" as time) ';
				if( $weekofday == 0x7f )
					$options .= ' AND ('.$btm_que.'OR'.$top_que.')';
				else{
					$top_days = '';
					$btm_days = '';
					for( $b_cnt=0; $b_cnt<6; $b_cnt++ ){
						if( $weekofday & ( 0x01 << $b_cnt ) ){
							if( $top_days !== '' )
								$top_days .= ',';
							$top_days .= (string)($b_cnt+1);
							if( $btm_days !== '' )
								$btm_days .= ',';
							$btm_days .= (string)$b_cnt;
						}
					}
					if( $weekofday & 0x40 ){
						if( $top_days !== '' )
							$top_days .= ',';
						$top_days .= '0';
						if( $btm_days !== '' )
							$btm_days .= ',';
						$btm_days .= '6';
					}
					$options .= ' AND ((WEEKDAY(starttime) IN ('.$top_days.') AND'.$top_que.') OR (WEEKDAY(starttime) IN ('.$btm_days.') AND'.$btm_que.'))';
				}
			}
		}else
			$options .= self::setWeekofdays( $weekofday );

		$options .= ' ORDER BY starttime ASC  LIMIT '.$limit ;

		$recs = array();
		try {
			$recs = self::createRecords( PROGRAM_TBL, $options );
		}
		catch( Exception $e ){
			throw $e;
		}
		return $recs;
	}

	private static function setWeekofdays( $weekofday = 0x7f ){
		if( $weekofday != 0x7f ){
			$weeks = '';
			for( $b_cnt=0; $b_cnt<7; $b_cnt++ ){
				if( $weekofday & ( 0x01 << $b_cnt ) ){
					if( $weeks !== '' )
						$weeks .= ',';
					$weeks .= (string)$b_cnt;
				}
			}
			return ' AND WEEKDAY(starttime) IN ('.$weeks.')';
		}else
			return '';
	}

	private function getPrograms(){
		if( $this->__id == 0 ) return false;
		$recs = array();
		try {
			 $recs = self::search( $this->keyword, $this->use_regexp, $this->collate_ci, $this->ena_title, $this->ena_desc, $this->typeGR, $this->typeBS, $this->typeCS, $this->typeEX,
			 						$this->category_id, $this->channel_id, $this->weekofdays, $this->prgtime, $this->period, $this->sub_genre, $this->first_genre );
		}
		catch( Exception $e ){
			throw $e;
		}
		return $recs;
	}

	public function keyid_acquire( $shm_id, $sem_key ){
		if( $this->__id == 0 ) return;

		// keyword_id排他処理
		while(1){
			if( sem_acquire( $sem_key ) === TRUE ){
				// keyword_id占有チェック
				$shm_cnt = SEM_KW_START;
				do{
					if( shmop_read_surely( $shm_id, $shm_cnt ) === (int)$this->__id ){
						while( sem_release( $sem_key ) === FALSE )
							usleep( 100 );
						usleep( 1000 );
						continue 2;
					}
				}while( ++$shm_cnt < SEM_KW_START+SEM_KW_MAX );

				// keyword_id占有
				$shm_cnt = SEM_KW_START;
				do{
					if( shmop_read_surely( $shm_id, $shm_cnt ) !== 0 )
						continue;
					shmop_write_surely( $shm_id, $shm_cnt, (int)$this->__id );
					while( sem_release( $sem_key ) === FALSE )
						usleep( 100 );
					$this->shm_id  = $shm_id;
					$this->sem_key = $sem_key;
					$this->shm_cnt = $shm_cnt;
					break 2;
				}while( ++$shm_cnt < SEM_KW_START+SEM_KW_MAX );
				while( sem_release( $sem_key ) === FALSE )
					usleep( 100 );
				usleep( 2000 );
			}
		}
	}

	public function keyid_release(){
		// keyword_id開放
		while( sem_acquire( $this->sem_key ) === FALSE )
			usleep( 100 );
		shmop_write_surely( $this->shm_id, $this->shm_cnt, 0 );
		$this->shm_cnt = 0;
		while( sem_release( $this->sem_key ) === FALSE )
			usleep( 100 );
	}

	public function reservation( $wave_type, $shm_id=false, $sem_key=false ){
		if( $this->__id == 0 ) return;

		if( $shm_id !== false )
			$this->keyid_acquire( $shm_id, $sem_key );	// keyword_id占有
		$precs = array();
		try {
			$precs = $this->getPrograms();
		}
		catch( Exception $e ){
			$this->keyid_release();	// keyword_id開放
			throw $e;
		}
		if( count( $precs ) > 0 ){
			// 一気に録画予約
			foreach( $precs as $rec ){
				try {
					$rec_permission = (boolean)$rec->autorec || ( (int)$rec->split_time>0 && $rec->split_time==$this->split_time ) ? TRUE : FALSE;
					if( $rec_permission && ( $wave_type==='*' || $rec->type===$wave_type || ( $wave_type==='BS' && $rec->type==='CS' ) || ( $wave_type==='CS' && $rec->type==='BS' ) ) ){
						$pieces = explode( ':', Reservation::simple( $rec->id, (int)$this->__id, $this->autorec_mode, $this->discontinuity ) );
						if( (int)$pieces[0] ){
							usleep( 1000 );		// 書き込みがDBに反映される時間を見極める。
							// 最終回フラグ
							if( (int)$pieces[4] && (int)$this->rest_alert!=0 && (int)$this->rest_alert!=3 ){
								$this->rest_alert = 3;
								$this->update();
							}
						}
					}
				}
				catch( Exception $e ){
					// 無視
				}
			}
			if( (int)$this->rest_alert == 2 ){
				$this->rest_alert = 1;
				$this->update();
			}
		}else{
			switch( (int)$this->rest_alert ){
				case 2:
					if( $wave_type!=='*' || date( 'H', time() )!=='00' )
						break;
				case 1:
					reclog( autoid_button($this->__id).'『'.htmlspecialchars($this->keyword).'』は、該当番組がありません。', EPGREC_WARN );
					$this->rest_alert = 2;
					$this->update();
					break;
				case 4:
					if( $wave_type!=='*' || date( 'H', time() )!=='00' )
						break;
				case 3:
					reclog( autoid_button($this->__id).'『'.htmlspecialchars($this->keyword).'』の最終回は、放送されました。', EPGREC_WARN );
					$this->rest_alert = 4;
					$this->update();
					break;
			}
		}
		$this->keyid_release();	// keyword_id開放
	}

	// キーワード編集対応にて下の関数より分離
	public function rev_delete( $rec_now = FALSE ){
		if( $this->id == 0 ) return;

		$precs = array();
		try {
			$del_que = 'WHERE complete=0 AND autorec='.$this->id;
			// 録画中の予約の有無
			if( !$rec_now )
				$del_que .= ' AND starttime>now()';
			$precs = self::createRecords( RESERVE_TBL, $del_que );
		}
		catch( Exception $e ){
			return;
		}
		// 一気にキャンセル
		foreach( $precs as $reserve ){
			try {
				Reservation::cancel( $reserve->id );
				usleep( 100 );		// あんまり時間を空けないのもどう?
			}
			catch( Exception $e ){
				// 無視
			}
		}
	}

	public function delete(){
		$this->rev_delete( TRUE );
		try {
			parent::delete();
		}
		catch( Exception $e ){
			throw $e;
		}
	}

	// staticなファンクションはオーバーライドできない
	static function createKeywords( $options = '' ){
		$retval = array();
		$arr = array();
		try{
			$tbl = new self();
			$sqlstr = 'SELECT * FROM '.$tbl->__table.' ' .$options;
			$result = $tbl->__query( $sqlstr );
		}
		catch( Exception $e ){
			throw $e;
		}
		if( $result === false ) throw new exception('レコードが存在しません');
		while ($row = mysqli_fetch_array($result, MYSQLI_ASSOC)){
			array_push( $retval, new self('id', $row['id'], $row) );
		}
		return $retval;
	}

	public function __destruct(){
		parent::__destruct();
	}
}
?>
