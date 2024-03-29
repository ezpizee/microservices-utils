<?php

namespace Ezpizee\Utils;

use Ezpizee\ContextProcessor\DBO;

class NestedTree
{
    const ROOT_ID = 'a0a0a0a0a0a0';

    /** @var DBO $dbo */
    private DBO $dbo;
    private string $table;
    private string $referenceId = ''; // ordering_item
    private string $orderingPosition = '';
    private string $parentId = '';
    private string $editId = '';
    private string $insertId = '';
    private string $nodePath = '';
    private string $newNodePath = '';
    private string $pathMD5 = '';
    private string $newNodeAlias = '';
    private string $msg = '';
    private string $limit = '';
    private array $updateStatementList = [];
    private array $insertFieldList = [];
    private array $insertValueList = [];
    private bool $exists = false;
    private bool $updateChildren = false;
    private bool $error = false;
    private int $newNodeLevel = 0;
    private int $parentRgt = 0;
    private int $nodeLft = 0;
    private int $nodeRgt = 0;
    //private int $parentLft = 0;

    public function __construct(DBO $dbo, string $table)
    {
        $this->table = $table;
        $this->dbo = $dbo;
    }

    public function setQueryLimit(string $limit): void {$this->limit = $limit;}

    public function getBranch(string $nodeId, string $ignoreBranch='', int $depth=1): array
    {
        return $this->dbo->loadAssocList($this->branchQueryStatement($nodeId, $ignoreBranch, $depth));
    }

    public function getTree(string $ignoreBranch=''): array
    {
        return $this->dbo->loadAssocList($this->treeQueryStatement($ignoreBranch));
    }

    public function delete(string $id): bool
    {
        $queries = array();
        $queries[] = 'LOCK TABLES ' . $this->table . ' WRITE;';
        $queries[] = 'SELECT '.'@myLeft := lft, @myRight := rgt, @myWidth := rgt - lft + 1 FROM ' . $this->table . ' WHERE id = ' . $this->dbo->quote($id) . ';';
        $queries[] = 'DELETE '.'FROM ' . $this->table . ' WHERE lft BETWEEN @myLeft AND @myRight;';
        $queries[] = 'UPDATE ' . $this->table . ' SET rgt = rgt - @myWidth WHERE rgt > @myRight;';
        $queries[] = 'UPDATE ' . $this->table . ' SET lft = lft - @myWidth WHERE lft > @myRight;';
        $queries[] = 'UNLOCK TABLES';
        $this->dbo->exec(implode("\n", $queries));
        return empty($this->dbo->getErrors());
    }

    public function store(string $parentId,
                          string $editId,
                          string $title,
                          string $referenceId,
                          string $orderingPosition,
                          array $updateQuery,
                          array $insertFields,
                          array $insertValues): void
    {
        $this->parentId = $parentId ?? self::ROOT_ID;
        $this->editId = $editId;
        $this->referenceId = $referenceId; // ordering_item
        $this->orderingPosition = $orderingPosition ?? 'after';
        $this->updateStatementList = $updateQuery;
        $this->insertFieldList = $insertFields;
        $this->insertValueList = $insertValues;
        $this->storeSetAlias($title);
        $this->storePreparePropertiesByParent();
        $this->checkExistence();

        if (!$this->exists) {

            $this->insertFieldList[] = 'lft';
            $this->insertValueList[] = '@myPosition+1';

            $this->insertFieldList[] = 'rgt';
            $this->insertValueList[] = '@myPosition+2';

            $this->insertFieldList[] = $this->dbo->quoteName('level');
            $this->insertValueList[] = $this->newNodeLevel;

            $this->insertFieldList[] = 'path';
            $this->insertValueList[] = $this->dbo->quote($this->newNodePath);
            $this->insertFieldList[] = 'path_md5';
            $this->insertValueList[] = $this->dbo->quote($this->pathMD5);

            $this->insertFieldList[] = 'alias';
            $this->insertValueList[] = $this->dbo->quote($this->newNodeAlias);

            $this->updateStatementList[] = $this->dbo->quoteName('level').'='.$this->dbo->quote($this->newNodeLevel);
            $this->updateStatementList[] = 'path='.$this->dbo->quote($this->newNodePath);
            $this->updateStatementList[] = 'path_md5='.$this->dbo->quote($this->pathMD5);
            $this->updateStatementList[] = 'alias='.$this->dbo->quote($this->newNodeAlias);

            $queries = $this->storeQueryStatements();
            //die(implode("\n", $queries));
            $failed = false;
            if (sizeof($queries) > 0) {
                $this->dbo->setQuery(implode("\n", $queries));
                $this->dbo->execute();
                $failed = !empty($this->dbo->getErrors());
                $this->msg = $failed ? 'FAILED_TO_ADD' : 'SUCCESS';
            }
            if (!$failed && $this->updateChildren) {
                $exec = $this->storeUpdateMovedNodeChildren();
                $failed = !empty($this->dbo->getErrors()) || !$exec;
                $this->msg = $failed ? 'FAILED_TO_UPDATE_CHILDREN' : 'SUCCESS';
            }
            if (!$this->editId && !$failed) {
                $this->editId = $this->dbo->lastInsertId();
                if (!$this->editId) {
                    $sql = 'SELECT id '.'FROM '.$this->table.' WHERE path_md5='.$this->dbo->quote($this->pathMD5);
                    $row = $this->dbo->loadAssoc($sql);
                    if (!empty($row)) {
                        $this->editId = $row['id'];
                    }
                }
            }
            if ($this->editId && !$failed) {
                $this->msg = 'SUCCESS';
                $this->insertId = $this->editId;
            }
        }
        else {
            $this->error = true;
            $this->msg = 'CATEGORY_ALREADY_EXIST';
        }
    }

    public function branchQueryStatement(string $nodeId, string $ignoreBranch='', int $depth=1, string $channel=''): string
    {

        /*$query = 'SELECT node.*, (COUNT(parent.id) - 1) AS depth
                        FROM '.$this->table.' AS node,
                                '.$this->table.' AS parent
                        WHERE node.lft BETWEEN parent.lft AND parent.rgt
                                AND node.id = '.$this->dbo->quote($nodeId).'
                        GROUP BY node.name
                        ORDER BY node.lft';*/

        if ($channel) {
            $channel = $this->channelConditions($channel, 'node');
        }

        $subQuery = 'SELECT node.*,(node.level-1) AS depth '.'FROM '.$this->table.' AS node WHERE node.id = '.$this->dbo->quote($nodeId);
        $query = 'SELECT node.*, (COUNT(parent.id) - (sub_tree.depth + 1)) AS depth'.'
        FROM '.$this->table.' AS node,
                '.$this->table.' AS parent,
                '.$this->table.' AS sub_parent,
                ('.$subQuery.') AS sub_tree
        WHERE node.lft BETWEEN parent.lft AND parent.rgt
            AND node.lft BETWEEN  sub_parent.lft AND sub_parent.rgt
            AND sub_parent.id = sub_tree.id '.
            ($channel ? ' AND ' . $channel : '').
            (!empty($ignoreBranch) ? ' AND node.id != ' . $this->dbo->quote($ignoreBranch) : '').'
        GROUP BY node.id
        '.($depth?'HAVING depth <= ' . $depth : '').'
        ORDER BY node.lft'.($this->limit ? ' ' . $this->limit : '');

        return $query;
    }

    public function treeQueryStatement(string $ignoreBranch='', string $channel=''): string
    {
        if ($ignoreBranch) {
            $children = $this->getBranch($ignoreBranch, 0, 0);
            if ($children) {
                $arr = array();
                foreach ($children as $child) {
                    $arr[] = $child['id'];
                }
                $ignoreBranch = implode(',', $arr);
            }
        }

        if ($channel) {
            $channel = $this->channelConditions($channel, 'node');
        }

        $query = 'SELECT node.*,(COUNT(parent.id) - 1) AS depth'.' 
            FROM '.$this->table.' AS node,'.$this->table.' AS parent 
            WHERE (node.lft BETWEEN parent.lft AND parent.rgt) 
            AND (node.rgt BETWEEN parent.lft AND parent.rgt) '.
            ($channel ? ' AND '.$channel : '').
            ($ignoreBranch ? ' AND node.id NOT IN('.$this->dbo->quote($ignoreBranch).')' : '').'
            GROUP BY node.id 
            ORDER BY node.lft'.($this->limit ? ' ' . $this->limit : '');

        return $query;
    }

    public function getError(): bool { return $this->error; }

    public function getMessage(): string { return $this->msg; }

    public function getEditId(): string {return $this->editId;}

    public function getInsertId(): string {return $this->insertId;}

    private function channelConditions(string $channel, string $tbAlias=''): string
    {
        if ($channel) {
            $channelCondition = [];
            $arr = explode(',', $channel);
            foreach ($arr as $chn) {
                $channelCondition[] = ($tbAlias?$tbAlias.'.':'').'channel LIKE '.$this->dbo->quote('%"'.$chn.'"');
                $channelCondition[] = ($tbAlias?$tbAlias.'.':'').'channel LIKE '.$this->dbo->quote('"'.$chn.'"%');
                $channelCondition[] = ($tbAlias?$tbAlias.'.':'').'channel LIKE '.$this->dbo->quote('%"'.$chn.'"%');
            }
            $channel = '('.implode(' OR ', $channelCondition).')';
        }
        return $channel;
    }

    private function checkExistence(): bool
    {
        $query = 'SELECT id '.'
        FROM ' . $this->table . '
        WHERE path_md5="' . $this->pathMD5 . '"' . ($this->editId ? ' AND id!='.$this->editId : '');
        $row = $this->dbo->loadAssoc($query);
        $this->exists = !empty($row) && isset($row['id']);
        return $this->exists;
    }

    private function getChildren(string $id): array
    {
        $query = 'SELECT id,path,alias,parent_id,lft,rgt,'.$this->dbo->quoteName('level').' 
        FROM ' . $this->table . '
        WHERE parent_id=' . $this->dbo->quote($id) . ' 
        ORDER BY lft';
        return $this->dbo->loadAssocList($query);
    }

    private function getNode(string $id): array
    {
        $query = 'SELECT id,path,alias,parent_id,lft,rgt,'.$this->dbo->quoteName('level').'
        FROM ' . $this->table . '
        WHERE id='.$this->dbo->quote($id);
        $row = $this->dbo->loadAssoc($query);

        // Check for no $row returned
        if (empty($row)) {
            return [];
        }

        // Do some simple calculations.
        $row['numChildren'] = (int) ($row['rgt'] - $row['lft'] - 1) / 2;
        $row['width'] = (int) $row['rgt'] - (int)$row['lft'] + 1;

        return $row;
    }

    private function storeQueryStatements(): array
    {
        // 1. add new node
        if (empty($this->editId)) {

            // 1.1. item
            if (!empty($this->referenceId)) {

                // 1.1.1. before
                if ($this->orderingPosition === "before") {
                    $rows = $this->getChildren($this->parentId);
                    $length = sizeof($rows);
                    if ($length) {
                        foreach ($rows as $i=>$row) {
                            if ($row['id'] === $this->referenceId) {
                                if ($i===0) {
                                    return $this->storeAddNodeQueryAsArray('first');
                                }
                                else if ($i===$length - 1) {
                                    return $this->storeAddNodeQueryAsArray('after', $rows[$length - 2]['id']);
                                }
                                else {
                                    return $this->storeAddNodeQueryAsArray('after', $rows[$i-1]['id']);
                                }
                            }
                        }
                    }
                }

                // 1.1.2. after
                else if ($this->orderingPosition === "after") {
                    return $this->storeAddNodeQueryAsArray($this->orderingPosition, $this->referenceId);
                }
            }

            // 1.2. first
            else if ($this->orderingPosition === 'first') {
                $rows = $this->getChildren($this->parentId);
                $length = sizeof($rows);
                if ($length) {
                    return $this->storeAddNodeQueryAsArray('first');
                }
                else {
                    return $this->storeAddNodeQueryAsArray('last');
                }
            }

            // 1.3. no-item or last
            else {
                return $this->storeAddNodeQueryAsArray('last');
            }
        }

        // 2. update / move node
        else {

            $row = $this->getNode($this->editId);
            $this->nodeLft = $row['lft'];
            $this->nodeRgt = $row['rgt'];
            $this->nodePath = $row['path'];
            $this->updateChildren = $this->parentId !== $row['parent_id'];
            if ($this->referenceId) {
                return $this->storeMoveNodeByReferenceNodeQueryAsArray();
            }
            else if ($this->orderingPosition === 'first') {
                $rows = $this->getChildren($this->parentId);
                $length = sizeof($rows);
                if ($length) {
                    $row = $rows[0];
                    $this->referenceId = $row['id'];
                    $this->orderingPosition = 'before';
                    return $this->storeMoveNodeByReferenceNodeQueryAsArray();
                }
                else {
                    return $this->storeMoveNodeAsLastChildNodeQueryAsArray();
                }
            }
            else if ($this->orderingPosition === 'last') {
                return $this->storeMoveNodeAsLastChildNodeQueryAsArray();
            }
            else if (!$this->updateChildren && !$this->referenceId) {
                return $this->storeUpdateNodeQueryAsArray();
            }
            else {
                return $this->storeMoveNodeAsLastChildNodeQueryAsArray();
            }
        }

        return [];
    }

    private function storeUpdateMovedNodeChildren(): bool
    {
        $row = $this->getNode($this->editId);
        $query = 'UPDATE ' . $this->table . ' SET
        path=CONCAT("'.$row['path'].'/",alias)
        ,path_md5=MD5(CONCAT("'.$row['path'].'/",alias))
        ,'.$this->dbo->quoteName('level').'='.($this->newNodeLevel+1).'
        WHERE path LIKE "'.$this->nodePath.'/%" AND id!='.$this->dbo->quote($this->editId);
        return $this->dbo->exec($query);
    }

    private function storeSetAlias(string $title): void
    {
        if (!empty($this->parentId)) {
            $this->newNodeAlias = PathUtil::toSlug($title);
        }
    }

    private function storePreparePropertiesByParent(): void
    {
        if (!empty($this->parentId) && $this->newNodeAlias) {
            $row = $this->getNode($this->parentId);
            if (sizeof($row) > 0) {
                //$this->parentLft = $row['lft'];
                $this->parentRgt = $row['rgt'];
                $this->newNodeLevel = ((int)$row['level'])+1;
                $this->newNodePath = $row['path'].($row['path']?'/':'').$this->newNodeAlias;
                $this->pathMD5 = md5($this->newNodePath);
            }
        }
    }

    private function storeUpdateNodeQueryAsArray(): array
    {
        $queries = [];
        $set = is_array($this->updateStatementList)
            ? implode(',', $this->updateStatementList)
            : $this->updateStatementList;
        $queries[] = 'UPDATE ' . $this->table . ' SET ' . $set . ' WHERE id='.$this->dbo->quote($this->editId). ';';
        return $queries;
    }

    private function storeMoveNodeByReferenceNodeQueryAsArray(): array
    {
        $queries = [];
        $newPos = 0;
        $row = $this->getNode($this->referenceId);

        if (!empty($row)) {
            if ($this->orderingPosition === "before") {
                $newPos = $row['lft'];
            } else if ($this->orderingPosition === "after") {
                $newPos = $row['rgt'] + 1;
            }

            $oldRgt = $this->nodeRgt;
            $width = $this->nodeRgt - $this->nodeLft + 1;
            $distance = $newPos - $this->nodeLft;
            $tmpPos = $this->nodeLft;
            if ($distance < 0) {
                $distance -= $width;
                $tmpPos += $width;
            }

            // Lock the table for writing.
            $queries[] = 'LOCK TABLES ' . $this->table . ' WRITE;';

            // create new space for subtree
            $queries[] = 'UPDATE ' . $this->table . ' SET lft = lft + ' . $width . ' WHERE lft >= ' . $newPos . ';';
            $queries[] = 'UPDATE ' . $this->table . ' SET rgt = rgt + ' . $width . ' WHERE rgt >= ' . $newPos . ';';

            // move subtree into new space
            $queries[] = 'UPDATE ' . $this->table . ' SET lft = lft + ' . $distance . ', rgt = rgt + ' . $distance . ' WHERE lft >= ' . $tmpPos . ' AND rgt < ' . $tmpPos . ' + ' . $width . ';';

            // remove old space vacated by subtree
            $queries[] = 'UPDATE ' . $this->table . ' SET lft = lft - ' . $width . ' WHERE lft > ' . $oldRgt . ';';
            $queries[] = 'UPDATE ' . $this->table . ' SET rgt = rgt - ' . $width . ' WHERE rgt > ' . $oldRgt . ';';

            // update other properties
            $queries[] = 'UPDATE ' . $this->table .
                ' SET ' . (is_array($this->updateStatementList) ? implode(',', $this->updateStatementList) : $this->updateStatementList) .
                ' WHERE id=' . $this->dbo->quote($this->editId) . ';';

            // unlock
            $queries[] = 'UNLOCK TABLES';
        }

        return $queries;
    }

    private function storeMoveNodeAsLastChildNodeQueryAsArray(): array
    {
        $queries = array();
        $queries[] = 'LOCK TABLES '.$this->table.' WRITE;';

        // step 0: Initialize parameters.
        $queries[] = 'SELECT
            @node_id := '.$this->editId.',
            @node_pos_left := '.$this->nodeLft.',
            @node_pos_right := '.$this->nodeRgt.',
            @parent_id := '.$this->parentId.',
            @parent_pos_right := '.$this->parentRgt.';';

        $queries[] = 'SELECT
            @node_size := @node_pos_right - @node_pos_left + 1;';

        // step 1: temporary "remove" moving node
        $queries[] = 'UPDATE ' . $this->table . ' SET lft = 0-(lft), rgt = 0-(rgt) WHERE lft >= @node_pos_left AND rgt <= @node_pos_right;';

        // step 2: decrease left and/or right position values of currently 'lower' items (and parents)
        $queries[] = 'UPDATE ' . $this->table . ' SET lft = lft - @node_size WHERE lft > @node_pos_right;';
        $queries[] = 'UPDATE ' . $this->table . ' SET rgt = rgt - @node_size WHERE rgt > @node_pos_right;';

        // step 3: increase left and/or right position values of future 'lower' items (and parents)
        $queries[] = 'UPDATE ' . $this->table . ' SET lft = lft + @node_size WHERE lft >= IF(@parent_pos_right > @node_pos_right, @parent_pos_right - @node_size, @parent_pos_right);';
        $queries[] = 'UPDATE ' . $this->table . ' SET rgt = rgt + @node_size WHERE rgt >= IF(@parent_pos_right > @node_pos_right, @parent_pos_right - @node_size, @parent_pos_right);';

        // step 4: move node (ant it's subnodes) and update it's parent item id
        $queries[] = 'UPDATE ' . $this->table . ' SET ' .
            'lft = 0-(lft)+IF(@parent_pos_right > @node_pos_right, @parent_pos_right - @node_pos_right - 1, @parent_pos_right - @node_pos_right - 1 + @node_size),'.
            'rgt = 0-(rgt)+IF(@parent_pos_right > @node_pos_right, @parent_pos_right - @node_pos_right - 1, @parent_pos_right - @node_pos_right - 1 + @node_size) '.
            'WHERE lft <= 0-@node_pos_left AND rgt >= 0-@node_pos_right;';
        $queries[] = 'UPDATE ' . $this->table . ' SET parent_id = @parent_id WHERE id = @node_id;';

        // step 5: update other properties
        $set = is_array($this->updateStatementList)
            ? implode(',', $this->updateStatementList)
            : $this->updateStatementList;
        $queries[] = 'UPDATE ' . $this->table . ' SET ' . $set . ' WHERE id=' . $this->dbo->quote($this->editId) . ';';

        // unlock
        $queries[] = 'UNLOCK TABLES';

        return $queries;
    }

    private function storeAddNodeQueryAsArray(string $position, string $nodeId=''): array
    {
        $queries = [];
        $queries[] = 'LOCK TABLES '.$this->table.' WRITE;';

        if ($nodeId) {
            $queries[] = 'SELECT @myPosition := rgt '.'FROM ' . $this->table . ' WHERE id=' . $this->dbo->quote($nodeId) . ';';
            $queries[] = 'UPDATE '.$this->table.' SET lft = lft + 2 WHERE lft > @myPosition;';
            $queries[] = 'UPDATE '.$this->table.' SET rgt = rgt + 2 WHERE rgt > @myPosition;';
        }
        else if ($position === 'first') {
            $queries[] = 'SELECT @myPosition := lft '.'FROM '.$this->table.' WHERE id='.$this->dbo->quote($this->parentId).';';
            $queries[] = 'UPDATE '.$this->table.' SET lft = lft + 2 WHERE lft > @myPosition;';
            $queries[] = 'UPDATE '.$this->table.' SET rgt = rgt + 2 WHERE rgt > @myPosition;';
        }
        else if ($position === 'last') {
            $queries[] = 'SELECT @myPosition := (rgt-1) '.'FROM '.$this->table.' WHERE id='.$this->dbo->quote($this->parentId).';';
            $queries[] = 'UPDATE '.$this->table.' SET lft = lft + 2 WHERE lft > @myPosition;';
            $queries[] = 'UPDATE '.$this->table.' SET rgt = rgt + 2 WHERE rgt > @myPosition;';
        }

        $fields = is_array($this->insertFieldList)
            ? implode(',', $this->insertFieldList)
            : $this->insertFieldList;
        $queries[] = 'INSERT '.'INTO ' . $this->table . '(' . $fields . ') 
        VALUES(' . implode(',', $this->insertValueList) . ');';

        $queries[] = 'UNLOCK TABLES';

        return $queries;
    }
}