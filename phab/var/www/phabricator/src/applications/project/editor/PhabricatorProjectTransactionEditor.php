<?php

final class PhabricatorProjectTransactionEditor
  extends PhabricatorApplicationTransactionEditor {

  private $isMilestone;

  private function setIsMilestone($is_milestone) {
    $this->isMilestone = $is_milestone;
    return $this;
  }

  public function getIsMilestone() {
    return $this->isMilestone;
  }

  public function getEditorApplicationClass() {
    return 'PhabricatorProjectApplication';
  }

  public function getEditorObjectsDescription() {
    return pht('Projects');
  }

  public function getTransactionTypes() {
    $types = parent::getTransactionTypes();

    $types[] = PhabricatorTransactions::TYPE_EDGE;
    $types[] = PhabricatorTransactions::TYPE_VIEW_POLICY;
    $types[] = PhabricatorTransactions::TYPE_EDIT_POLICY;
    $types[] = PhabricatorTransactions::TYPE_JOIN_POLICY;

    return $types;
  }

  protected function validateAllTransactions(
    PhabricatorLiskDAO $object,
    array $xactions) {

    $errors = array();

    // Prevent creating projects which are both subprojects and milestones,
    // since this does not make sense, won't work, and will break everything.
    $parent_xaction = null;
    foreach ($xactions as $xaction) {
      switch ($xaction->getTransactionType()) {
        case PhabricatorProjectParentTransaction::TRANSACTIONTYPE:
        case PhabricatorProjectMilestoneTransaction::TRANSACTIONTYPE:
          if ($xaction->getNewValue() === null) {
            continue;
          }

          if (!$parent_xaction) {
            $parent_xaction = $xaction;
            continue;
          }

          $errors[] = new PhabricatorApplicationTransactionValidationError(
            $xaction->getTransactionType(),
            pht('Invalid'),
            pht(
              'When creating a project, specify a maximum of one parent '.
              'project or milestone project. A project can not be both a '.
              'subproject and a milestone.'),
            $xaction);
          break;
          break;
      }
    }

    $is_milestone = $this->getIsMilestone();

    $is_parent = $object->getHasSubprojects();

    foreach ($xactions as $xaction) {
      switch ($xaction->getTransactionType()) {
        case PhabricatorTransactions::TYPE_EDGE:
          $type = $xaction->getMetadataValue('edge:type');
          if ($type != PhabricatorProjectProjectHasMemberEdgeType::EDGECONST) {
            break;
          }

          if ($is_parent) {
            $errors[] = new PhabricatorApplicationTransactionValidationError(
              $xaction->getTransactionType(),
              pht('Invalid'),
              pht(
                'You can not change members of a project with subprojects '.
                'directly. Members of any subproject are automatically '.
                'members of the parent project.'),
              $xaction);
          }

          if ($is_milestone) {
            $errors[] = new PhabricatorApplicationTransactionValidationError(
              $xaction->getTransactionType(),
              pht('Invalid'),
              pht(
                'You can not change members of a milestone. Members of the '.
                'parent project are automatically members of the milestone.'),
              $xaction);
          }
          break;
      }
    }

    return $errors;
  }

  protected function requireCapabilities(
    PhabricatorLiskDAO $object,
    PhabricatorApplicationTransaction $xaction) {

    switch ($xaction->getTransactionType()) {
      case PhabricatorProjectNameTransaction::TRANSACTIONTYPE:
      case PhabricatorProjectStatusTransaction::TRANSACTIONTYPE:
      case PhabricatorProjectImageTransaction::TRANSACTIONTYPE:
      case PhabricatorProjectIconTransaction::TRANSACTIONTYPE:
      case PhabricatorProjectColorTransaction::TRANSACTIONTYPE:
        PhabricatorPolicyFilter::requireCapability(
          $this->requireActor(),
          $object,
          PhabricatorPolicyCapability::CAN_EDIT);
        return;
      case PhabricatorProjectLockTransaction::TRANSACTIONTYPE:
        PhabricatorPolicyFilter::requireCapability(
          $this->requireActor(),
          newv($this->getEditorApplicationClass(), array()),
          ProjectCanLockProjectsCapability::CAPABILITY);
        return;
      case PhabricatorTransactions::TYPE_EDGE:
        switch ($xaction->getMetadataValue('edge:type')) {
          case PhabricatorProjectProjectHasMemberEdgeType::EDGECONST:
            $old = $xaction->getOldValue();
            $new = $xaction->getNewValue();

            $add = array_keys(array_diff_key($new, $old));
            $rem = array_keys(array_diff_key($old, $new));

            $actor_phid = $this->requireActor()->getPHID();

            $is_join = (($add === array($actor_phid)) && !$rem);
            $is_leave = (($rem === array($actor_phid)) && !$add);

            if ($is_join) {
              // You need CAN_JOIN to join a project.
              PhabricatorPolicyFilter::requireCapability(
                $this->requireActor(),
                $object,
                PhabricatorPolicyCapability::CAN_JOIN);
            } else if ($is_leave) {
              // You usually don't need any capabilities to leave a project.
              if ($object->getIsMembershipLocked()) {
                // you must be able to edit though to leave locked projects
                PhabricatorPolicyFilter::requireCapability(
                  $this->requireActor(),
                  $object,
                  PhabricatorPolicyCapability::CAN_EDIT);
              }
            } else {
              // You need CAN_EDIT to change members other than yourself.
              PhabricatorPolicyFilter::requireCapability(
                $this->requireActor(),
                $object,
                PhabricatorPolicyCapability::CAN_EDIT);
            }
            return;
        }
        break;
    }

    return parent::requireCapabilities($object, $xaction);
  }

  protected function willPublish(PhabricatorLiskDAO $object, array $xactions) {
    // NOTE: We're using the omnipotent user here because the original actor
    // may no longer have permission to view the object.
    return id(new PhabricatorProjectQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withPHIDs(array($object->getPHID()))
      ->needAncestorMembers(true)
      ->executeOne();
  }

  protected function shouldSendMail(
    PhabricatorLiskDAO $object,
    array $xactions) {
    return true;
  }

  protected function getMailSubjectPrefix() {
    return pht('[Project]');
  }

  protected function getMailTo(PhabricatorLiskDAO $object) {
    return array(
      $this->getActingAsPHID(),
    );
  }

  protected function getMailCc(PhabricatorLiskDAO $object) {
    return array();
  }

  public function getMailTagsMap() {
    return array(
      PhabricatorProjectTransaction::MAILTAG_METADATA =>
        pht('Project name, hashtags, icon, image, or color changes.'),
      PhabricatorProjectTransaction::MAILTAG_MEMBERS =>
        pht('Project membership changes.'),
      PhabricatorProjectTransaction::MAILTAG_WATCHERS =>
        pht('Project watcher list changes.'),
      PhabricatorProjectTransaction::MAILTAG_OTHER =>
        pht('Other project activity not listed above occurs.'),
    );
  }

  protected function buildReplyHandler(PhabricatorLiskDAO $object) {
    return id(new ProjectReplyHandler())
      ->setMailReceiver($object);
  }

  protected function buildMailTemplate(PhabricatorLiskDAO $object) {
    $id = $object->getID();
    $name = $object->getName();

    return id(new PhabricatorMetaMTAMail())
      ->setSubject("{$name}")
      ->addHeader('Thread-Topic', "Project {$id}");
  }

  protected function buildMailBody(
    PhabricatorLiskDAO $object,
    array $xactions) {

    $body = parent::buildMailBody($object, $xactions);

    $uri = '/project/profile/'.$object->getID().'/';
    $body->addLinkSection(
      pht('PROJECT DETAIL'),
      PhabricatorEnv::getProductionURI($uri));

    return $body;
  }

  protected function shouldPublishFeedStory(
    PhabricatorLiskDAO $object,
    array $xactions) {
    return true;
  }

  protected function supportsSearch() {
    return true;
  }

  protected function applyFinalEffects(
    PhabricatorLiskDAO $object,
    array $xactions) {

    $materialize = false;
    $new_parent = null;
    foreach ($xactions as $xaction) {
      switch ($xaction->getTransactionType()) {
        case PhabricatorTransactions::TYPE_EDGE:
          switch ($xaction->getMetadataValue('edge:type')) {
            case PhabricatorProjectProjectHasMemberEdgeType::EDGECONST:
              $materialize = true;
              break;
          }
          break;
        case PhabricatorProjectParentTransaction::TRANSACTIONTYPE:
        case PhabricatorProjectMilestoneTransaction::TRANSACTIONTYPE:
          $materialize = true;
          $new_parent = $object->getParentProject();
          break;
      }
    }

    if ($new_parent) {
      // If we just created the first subproject of this parent, we want to
      // copy all of the real members to the subproject.
      if (!$new_parent->getHasSubprojects()) {
        $member_type = PhabricatorProjectProjectHasMemberEdgeType::EDGECONST;

        $project_members = PhabricatorEdgeQuery::loadDestinationPHIDs(
          $new_parent->getPHID(),
          $member_type);

        if ($project_members) {
          $editor = id(new PhabricatorEdgeEditor());
          foreach ($project_members as $phid) {
            $editor->addEdge($object->getPHID(), $member_type, $phid);
          }
          $editor->save();
        }
      }
    }

    // TODO: We should dump an informational transaction onto the parent
    // project to show that we created the sub-thing.

    if ($materialize) {
      id(new PhabricatorProjectsMembershipIndexEngineExtension())
        ->rematerialize($object);
    }

    if ($new_parent) {
      id(new PhabricatorProjectsMembershipIndexEngineExtension())
        ->rematerialize($new_parent);
    }

    return parent::applyFinalEffects($object, $xactions);
  }

  public function addSlug(PhabricatorProject $project, $slug, $force) {
    $slug = PhabricatorSlug::normalizeProjectSlug($slug);
    $table = new PhabricatorProjectSlug();
    $project_phid = $project->getPHID();

    if ($force) {
      // If we have the `$force` flag set, we only want to ignore an existing
      // slug if it's for the same project. We'll error on collisions with
      // other projects.
      $current = $table->loadOneWhere(
        'slug = %s AND projectPHID = %s',
        $slug,
        $project_phid);
    } else {
      // Without the `$force` flag, we'll just return without doing anything
      // if any other project already has the slug.
      $current = $table->loadOneWhere(
        'slug = %s',
        $slug);
    }

    if ($current) {
      return;
    }

    return id(new PhabricatorProjectSlug())
      ->setSlug($slug)
      ->setProjectPHID($project_phid)
      ->save();
  }

  public function removeSlugs(PhabricatorProject $project, array $slugs) {
    if (!$slugs) {
      return;
    }

    // We're going to try to delete both the literal and normalized versions
    // of all slugs. This allows us to destroy old slugs that are no longer
    // valid.
    foreach ($this->normalizeSlugs($slugs) as $slug) {
      $slugs[] = $slug;
    }

    $objects = id(new PhabricatorProjectSlug())->loadAllWhere(
      'projectPHID = %s AND slug IN (%Ls)',
      $project->getPHID(),
      $slugs);

    foreach ($objects as $object) {
      $object->delete();
    }
  }

  public function normalizeSlugs(array $slugs) {
    foreach ($slugs as $key => $slug) {
      $slugs[$key] = PhabricatorSlug::normalizeProjectSlug($slug);
    }

    $slugs = array_unique($slugs);
    $slugs = array_values($slugs);

    return $slugs;
  }

  protected function adjustObjectForPolicyChecks(
    PhabricatorLiskDAO $object,
    array $xactions) {

    $copy = parent::adjustObjectForPolicyChecks($object, $xactions);

    $type_edge = PhabricatorTransactions::TYPE_EDGE;
    $edgetype_member = PhabricatorProjectProjectHasMemberEdgeType::EDGECONST;

    $member_xaction = null;
    foreach ($xactions as $xaction) {
      if ($xaction->getTransactionType() !== $type_edge) {
        continue;
      }

      $edgetype = $xaction->getMetadataValue('edge:type');
      if ($edgetype !== $edgetype_member) {
        continue;
      }

      $member_xaction = $xaction;
    }

    if ($member_xaction) {
      $object_phid = $object->getPHID();

      if ($object_phid) {
        $project = id(new PhabricatorProjectQuery())
          ->setViewer($this->getActor())
          ->withPHIDs(array($object_phid))
          ->needMembers(true)
          ->executeOne();
        $members = $project->getMemberPHIDs();
      } else {
        $members = array();
      }

      $clone_xaction = clone $member_xaction;
      $hint = $this->getPHIDTransactionNewValue($clone_xaction, $members);
      $rule = new PhabricatorProjectMembersPolicyRule();

      $hint = array_fuse($hint);

      PhabricatorPolicyRule::passTransactionHintToRule(
        $copy,
        $rule,
        $hint);
    }

    return $copy;
  }

  protected function expandTransactions(
    PhabricatorLiskDAO $object,
    array $xactions) {

    $actor = $this->getActor();
    $actor_phid = $actor->getPHID();

    $results = parent::expandTransactions($object, $xactions);

    $is_milestone = $object->isMilestone();
    foreach ($xactions as $xaction) {
      switch ($xaction->getTransactionType()) {
        case PhabricatorProjectMilestoneTransaction::TRANSACTIONTYPE:
          if ($xaction->getNewValue() !== null) {
            $is_milestone = true;
          }
          break;
      }
    }

    $this->setIsMilestone($is_milestone);

    return $results;
  }

  protected function shouldApplyHeraldRules(
    PhabricatorLiskDAO $object,
    array $xactions) {
    return true;
  }

  protected function buildHeraldAdapter(
    PhabricatorLiskDAO $object,
    array $xactions) {

    // Herald rules may run on behalf of other users and need to execute
    // membership checks against ancestors.
    $project = id(new PhabricatorProjectQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withPHIDs(array($object->getPHID()))
      ->needAncestorMembers(true)
      ->executeOne();

    return id(new PhabricatorProjectHeraldAdapter())
      ->setProject($project);
  }

}