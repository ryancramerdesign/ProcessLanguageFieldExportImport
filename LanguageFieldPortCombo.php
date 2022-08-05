<?php namespace ProcessWire;

/**
 * LanguageFieldPort for ProFields FieldtypeCombo 
 * 
 * This port is under development and not yet functional. 
 *
 * ProcessWire 3.x, Copyright 2022 by Ryan Cramer
 * https://processwire.com
 *
 */
class LanguageFieldPortCombo extends LanguageFieldPort {
	
	public function usable() {
		return false; // TMP!!
		return $this->wire()->modules->isInstalled('FieldtypeCombo');
	}

	public function portable(Field $field) {
		return false; // TMP!!
		return wireInstanceOf($field->type, 'FieldtypeCombo');
	}

	public function export($pageId, Field $field) {
		// @todo needs implementation
		if($pageId && $field) {}
	}

	public function import($pageId, Field $field, array $row) {
		return false;
	}

}