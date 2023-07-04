<?php
/**
 * Wazabii DB - For main queries
 */
namespace PHPFuse\Query\Handlers;

class MySqliHandler {



	/**
	 * Build SELECT sql code (The method will be auto called in method build)
	 * @return self
	 */
	public function select() {


		
			$columns = is_null($this->columns) ? "*" : implode(",", $this->columns);
			$join = $this->buildJoin();
			$where = $this->buildWhere("WHERE", $this->where);
			$having = $this->buildWhere("HAVING", $this->having);
			$order = (!is_null($this->order)) ? " ORDER BY ".implode(",", $this->order) : "";
			$limit = $this->buildLimit();
			$this->sql = "{$this->explain}SELECT {$this->noCache}{$this->calRows}{$this->distinct}{$columns} FROM ".$this->getTable()."{$join}{$where}{$this->group}{$having}{$order}{$limit}{$this->union}";

		



		/*
		$myObject = new MySqliHandler();
		$myObject->test()->call($this);
		 */

		return $this;
	}

	/**
	 * Build INSERT sql code (The method will be auto called in method build)
	 * @return self
	 */
	public function insert() {
		$this->sql = "{$this->explain}INSERT INTO ".$this->getTable()." ".$this->buildInsertSet().$this->buildDuplicate();
		return $this;
	}

	/**
	 * Build UPDATE sql code (The method will be auto called in method build)
	 * @return self
	 */
	public function update() {
		$join = $this->buildJoin();
		$where = $this->buildWhere("WHERE", $this->where);
		$limit = $this->buildLimit();

		$this->sql = "{$this->explain}UPDATE ".$this->getTable()."{$join} SET ".$this->buildUpdateSet()."{$where}{$limit}";
		return $this;
	}

	/**
	 * Build DELETE sql code (The method will be auto called in method build)
	 * @return self
	 */
	public function delete() {
		$tbToCol = $this->buildTableToCol();
		$join = $this->buildJoin();
		$where = $this->buildWhere("WHERE", $this->where);
		$limit = $this->buildLimit();

		$this->sql = "{$this->explain}DELETE{$tbToCol} FROM ".$this->getTable()."{$join}{$where}{$limit}";
		return $this;
	}

	/**
	 * Build CREATE VIEW sql code (The method will be auto called in method build)
	 * @return self
	 */
	public function createView() {
		$this->select();
		$this->sql = "CREATE VIEW ".$this->viewName." AS {$this->sql}";
		return $this;
	}

	/**
	 * Build CREATE OR REPLACE VIEW sql code (The method will be auto called in method build)
	 * @return self
	 */
	public function replaceView() {
		$this->select();
		$this->sql = "CREATE OR REPLACE VIEW ".$this->viewName." AS {$this->sql}";
		return $this;
	}

	/**
	 * Build DROP VIEW sql code (The method will be auto called in method build)
	 * @return self
	 */
	public function dropView() {
		$this->sql = "DROP VIEW ".$this->viewName;
		return $this;
	}
}
