<?php

class WP_Libre_Formbuilder {
  const ERR_FORM_ID_EMPTY = 'You must supply a form id.';
  const FORM_SAVED = 'Form saved succesfully.';

  public static $instance;
  public $fields;

  public static function instance() {
    if (is_null(self::$instance)) {
      self::$instance = new WP_Libre_Formbuilder();
    }

    return self::$instance;
  }

  public function __construct() {
    add_action("init", [$this, "registerCPT"]);

    add_filter("user_can_richedit", function($x) {
      if (isset($GLOBALS["post"]) && $GLOBALS["post"]->post_type === "wplfb-field") {
        return false;
      }

      return $x;
    });

    add_action("save_post", function($post_id, $post) {
      if ($post->post_type === "wplfb-field") {
        $children = !isset($_POST["wplfb-field-children"]) ? 0 : $_POST["wplfb-field-children"];
        update_post_meta($post_id, "wplfb-field-children", $children);
      }
    }, 10, 2);

    add_action("add_meta_boxes", [$this, "tamperMetaBoxes"]);
    add_action("rest_api_init", [$this, "registerRESTRoutes"]);
  }

  public function registerCPT() {
    register_post_type("wplfb-field", apply_filters("wplfb_cpt_args", [
      "labels" => [
        "name" => _x("Form fields", "post type general name", "wp-libre-formbuilder"),
        "singular_name" => _x("Form field", "post type singular name", "wp-libre-formbuilder")
      ],
      "public" => false,
      "show_ui" => true,
      "show_in_menu" => "edit.php?post_type=wplf-form",
      "capability_type" => apply_filters("wplfb_cpt_capability_type", "post"),
      "capabilities" => apply_filters("wplfb_cpt_capabilities", []),
      "supports" => apply_filters("wplfb_cpt_supports", [
        "title",
        "editor",
        "custom-fields",
        "revisions"
      ]),
      "taxonomies" => apply_filters("wplfb_cpt_taxonomies", []),
      "show_in_rest" => true
    ]));
  }

  public function tamperMetaBoxes() {
    add_meta_box(
      "wplfb_field_options",
      "Field options",
      function($post) {
      ?>
      <label>
      <input type="checkbox" name="wplfb-field-children" value="1" <?=checked(1, get_post_meta($post->ID, "wplfb-field-children", true))?>>
        Field accepts children
      </label>
      <?php
        var_dump($post);
      },
      "wplfb-field",
      "advanced",
      "high",
      [$GLOBALS["post"]]
    );

    add_meta_box(
      "wplfb_buildarea",
      "Form builder",
      function() {
        echo "Hello!";
      },
      "wplf-form",
      "advanced",
      "high"
    );
  }

  public function registerRESTRoutes() {
    register_rest_route("wplfb", "/fields", [
      "methods" => "GET",
      "callback" => function (WP_REST_Request $request) {
        return $this->getFields($request);
      },
    ]);

    register_rest_route("wplfb", "/forms/forms", [
      "methods" => "GET",
      "callback" => function (WP_REST_Request $request) {
        return $this->getForms($request);
      },
    ]);

    register_rest_route("wplfb", "/forms/form", [
      "methods" => "GET",
      "callback" => function (WP_REST_Request $request) {
        return $this->getForm($request);
      },
    ]);

    register_rest_route("wplfb", "/forms/form", [
      "methods" => "POST",
      "callback" => function (WP_REST_Request $request) {
        return $this->saveForm($request);
      },
    ]);
  }

  public function getForms(WP_REST_Request $request) {
    // require auth for this
    $plist = get_posts([
      "post_type" => "wplf-form",
      "posts_per_page" => -1
    ]);

    foreach ($plist as $p) {
      $result[] = [
        "form" => $p,
        "fields" => get_post_meta($p->ID, "wplfb_fields", true)
      ];
    }

    return new WP_REST_Response($result);
  }

  public function getForm(WP_REST_Request $request) {
    $form_id = $request->get_param("form_id");

    if (is_null($form_id)) {
      return new WP_REST_Response([
        "error" => self::ERR_FORM_ID_EMPTY
      ]);
    }

    $p = get_post((int) $form_id);

    return new WP_REST_Response([
      "form" => $p,
      "fields" => get_post_meta($p->ID, "wplfb_fields", true)
    ]);
  }

  /**
   * For whatever reason $_POST and $request->get_body_params() are empty.
   * This goes around that.
   */
  public function getRequestBody() {
    // Maybe do error handling.
    return json_decode(file_get_contents('php://input'));
  }

  public function saveForm(WP_REST_Request $request) {
    $form_id = $request->get_param("form_id");
    $params = $this->getRequestBody();
    $fields = !empty($params->fields) ? $params->fields : false;
    $html = !empty($params->html) ? $params->html : false;

    if (!$fields) {
      return new WP_REST_Response([
        "error" => "No tree provided.",
      ]);
    }

    $is_insert = is_null($form_id);

    // Stop messing with the HTML!
    remove_all_filters("content_save_pre");

    $args = [
      "ID" => !$is_insert ? $form_id : 0,
      "post_content" => $html,
      "post_type" => "wplf-form",
      "post_status" => "publish",
    ];

    $fn = !$is_insert
      ? "wp_update_post"
      : "wp_insert_post";
    $insert = $fn($args);

    if (!is_wp_error($insert) && $insert !== 0) {
      update_post_meta($insert, "wplfb_fields", $fields); // Sanitize?
      return new WP_REST_Response([
        "success" => self::FORM_SAVED,
        "fields" => $fields
      ]);
    } else {
      return new WP_REST_Response([
        "error" => $insert
      ]);
    }

  }

  public function getField(WP_REST_Request $request) {

  }

  public function getFields(WP_REST_Request $request) {
    $codefields = apply_filters("wplfb-available-code-fields", $this->fields);
    // Pass later later with callback; does not contain fields from DB

    // Querying the DB is expensive, so fields from the DB are not loaded until necessary.
    $plist = get_posts(["post_type" => "wplfb-field", "posts_per_page" => -1]);
    foreach ($plist as $p) {
      $ok = $this->addField([
        "key" => $p->ID,
        "name" => $p->post_title,
        "html" => $p->post_content,
        "takesChildren" => \WPLFB\booleanify(get_post_meta($p->ID, "wplfb-field-children", true)),
      ]);

      if (!$ok) {
        // The key already exists; do not add it again or overwrite it.
        // Combine with a huge notice in field edit page.
        update_post_meta($p->ID, "wplfb-field-override", true);
      } else {
        // I'd love to store the DOM in meta ($ok["dom"]) but I do not want to do it every time.

      }
    }

    // Allow user to filter the result.
    $fields = apply_filters("wplfb-available-fields", $this->fields, $codefields);

    return new WP_REST_Response([
      "fields" => $fields,
    ]);
  }

  public function addField($data = []) {
    if (empty($data)) {
      throw new Exception("You must supply the field data");
    } else if (empty($data["key"])) {
      throw new Exception("Field key is mandatory. Numerical keys *will* override database entries.");
    } else if (empty($data["name"])) {
      throw new Exception("Field name is mandatory");
    } else if (empty($data["html"])) {
      throw new Exception("Field html is mandatory");
    } else if (!isset($data["takesChildren"])) {
      throw new Exception("You must spesify whether the field takes children");
    }

    if (!empty($this->fields[$data["key"]])) {
      return false;
    }

    $this->fields[$data["key"]] = [
      "name" => apply_filters("wplfb-field-name", $data["name"], $data),
      "html" => apply_filters("wplfb-field-html", $data["html"], $data), // Is this any good? Could be for something, not for this directly
      "dom" => apply_filters("wplfb-field-dom", $this->generateDOM($data["html"]), $data),
      "takesChildren" => apply_filters("wplfb-field-children", \WPLFB\booleanify($data["takesChildren"]), $data), // this isn't useful, remove
      "wplfbKey" => $data["key"], // this is a duplicate, get rid of it
    ]; // PHP casts it into an array if it's null.

    return $this->fields[$data["key"]];
  }

  public function generateDOM($html = '') {
    $DOM = new DOMDocument();
    $DOM->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

    $parseNode = function($node, $parseNode) use ($DOM) { // We have to pass the function as the second param or it isn't in scope.
      $parseAttrs = function($el){
        $attrs = false;

        if ($el->attributes) {
          $attrs = [];
          foreach ($el->attributes as $attr) {
            // `class` and `for` attributes cause trouble in React, rewrite them *there*, not here.
            // if (strtolower($attr->name) === "class") {
              // $attrs[$attr->name] = $attr->value;
            // } else {
              $attrs[$attr->name] = $attr->value;
            // }
          }
        }

        return $attrs;
      };


      return [
        "element" => !empty($node->tagName) ? $node->tagName : "TextNode",
        "textContent" => !empty($node->textContent) ? $node->textContent : false,
        "attributes" => $parseAttrs($node),
        "children" => $node->firstChild ? $parseNode($node->firstChild, $parseNode) : false, // And again. Gotta love PHP?
        "children_html" => $node->firstChild ? $DOM->saveHTML($node->firstChild) : false,
      ];
    };

    return $parseNode($DOM->firstChild, $parseNode); // <3
  }

  public function generateHTML($fields) {
    $fields = (array) $fields;

    $html = "";
    $chunks = [];
    $dom = new DOMDocument();
    $generateChunk = function ($id, $field) use (&$dom, &$chunks) {
      if (!empty($chunks[$id])) {
        return $chunks[$id];
      }

      // WTF, what do you mean tagName doesn't exist sometimes?
      // I fucking hate PHP.
      $tagName = $field->field->tagName ?? "mark";
      $attributes = !empty($field->field->attributes)
        ? $field->field->attributes
        : [];
      $element = $dom->createElement($tagName);

      foreach ($attributes as $key => $value) {
        $element->setAttribute($key, $value);
      }

      foreach ($field->children as $child) {
        $element->appendChild($chunks[$child]);
      }

      $chunks[$id] = $element;
      return $chunks[$id];
    };


    foreach ($fields as $id => $field) {
      if (!empty($field->children)) {
        foreach ($field->children as $child_id) {
          $generateChunk($child_id, $fields[$child_id]);
        }
      }

      if (empty($field->parent)) {
        $html .= $dom->saveHTML($generateChunk($id, $field));
      }
    }

    error_log(print_r($html, true));
    return $html;
  }
}
