<?php namespace ProcessWire;

/**
 * LanguageFieldPort for FieldtypePageTable
 *
 * ProcessWire 3.x, Copyright 2022 by Ryan Cramer
 * https://processwire.com
 *
 */
class LanguageFieldPortPageTable extends LanguageFieldPort {
	
	public function usable() {
		return $this->wire()->modules->isInstalled('FieldtypePageTable');
	}
	
	public function waitable() {
		return true;
	}

	public function portable(Field $field) {
		return wireInstanceOf($field->type, 'FieldtypePageTable');
	}

	public function export($pageId, Field $field) {
		
		$pageId = (int) $pageId;
		$ids = array();
		$table = $field->getTable();

		$sql = "SELECT data FROM $table WHERE pages_id=:id ORDER BY sort";
		$query = $this->wire()->database->prepare($sql);
		$query->bindValue(':id', $pageId, \PDO::PARAM_INT);
		$query->execute();

		while($row = $query->fetch(\PDO::FETCH_NUM)) {
			$ids[] = (int) $row[0];
		}

		$query->closeCursor();

		$n = 0;
		foreach($ids as $id) {
			$this->exporter()->exportPageAllFields($id);
			$n++;
		}
	}

	public function import($pageId, Field $field, array $row) {
		return false;
	}

}