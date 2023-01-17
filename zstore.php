<?php
class ControllerApiZStore extends Controller {
	public function moySklad() {
		if ($this->request->get['username'] && $this->request->get['key']) {
			$this->log->write('Authorization started');
			$data['username'] = $this->request->get['username'];
			$data['key'] = $this->request->get['key'];


			$ch = curl_init();
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
			curl_setopt($ch, CURLOPT_URL, $_SERVER['HTTP_HOST'] . '/index.php?route=api/login');
			$json = curl_exec($ch);
			curl_close($ch);
			$obj = json_decode($json);
			if ($obj->api_token) {
				$this->log->write('Authorization complete');
				$this->session->data['api_token'] = $obj->api_token;

				if (isset($this->request->get['do']) && $this->request->get['do'] == 'getAssortment') {
					$this->getWholeAssortment("https://online.moysklad.ru/api/remap/1.2/entity/assortment");
				} else {
					$input = json_decode(file_get_contents('php://input'), true);
					$url = (isset($input['events'])) ? $input['events'][0]['meta']['href'] : $input['meta']['href'];
					$result = $this->doCurlRequest($url, "GET");
					$result = json_decode($result, true);
					if (isset($result['positions'])) {
						/* $this->log->write('This is an array'); */
						$json = $this->doCurlRequest($result['positions']['meta']['href'], "GET");
						$result = json_decode($json, true);
						foreach ($result['rows'] as $position) {
							$assortment = $this->doCurlRequest($position['assortment']['meta']['href'], "GET");
							$product = json_decode($assortment, true);
							$this->addProduct($product);
						}
					} else {
						/* $this->log->write('This is one product'); */
						$product = $result;
						$this->addProduct($product);
					}
				}
			}
		} else {
			$this->log->write('Authorization failed');
		}
	}

	private function getWholeAssortment($url) {
		$assortment = $this->doCurlRequest($url, "GET");
		$assortment = json_decode($assortment, true);
		foreach($assortment['rows'] as $product) {
			/* $this->log->write($product); */
			$this->addProduct($product);
		}
		if (isset($assortment['meta']['nextHref'])) {
			$this->getWholeAssortment($assortment['meta']['nextHref']);
		}
	}

	private function addProduct($product) {
		$json = array();
		if (!isset($this->session->data['api_token'])) {
			$this->log->write("No API token sent");
		} else {
			try {
				$this->log->write("Check product: " . $product['externalCode']);
				/* throw new Exception('Some Error Message'); */
				if ($product['meta']['type'] == 'variant') {
					$product = $this->doCurlRequest($product['product']['meta']['href'], "GET");
					$product = json_decode($product, true);
				}
				if (isset($product['attributes'])) {
					foreach ($product['attributes'] as $attribute) {
						if ($attribute['name'] == 'Категория товара на сайте' && $attribute['value'] != "") {
							$product_to_categories = $this->productToCategory($attribute['value']);
						}
					}
				}
				$language_id = (int) $this->config->get('config_language_id');
				$store_id = (int) $this->config->get('config_store_id');
				$sku = $this->db->escape($product['externalCode']);
				$id = $product['id'];
				$name = $this->db->escape(str_replace('"', '&quot;', $product['name']));

				/* $this->log->write($product['salePrices']); */
				// get price for e-commerce
				$price_rub = '';
				foreach ($product['salePrices'] as $price) {
					if ($price['priceType'] == 'Цена интернет магазин' || $price['priceType']['name'] == 'Цена интернет магазин') {
						$price_rub = $price['value'] / 100;
						break;
					}
				}

				if ($price_rub == '') {
					$this->log->write('Price not found. Skip');
					$this->log->write('__________');
					return false;
				}

				// add short description
				if (isset($product['description'])) {
					$description = $this->db->escape($product['description']);
				}

				$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "product WHERE sku='$sku'");

				// add image
				if (isset($product['image'])) {
					$old_image_path = ($query->row['image']) ? $query->row['image'] : '';
					$relative_path = 'catalog/products/';
					$relative_path_picture = $relative_path . $product['image']['filename'];
					if ($relative_path_picture != $old_image_path || !file_exists(DIR_IMAGE . $old_image_path)) {
						$file_name = $product['image']['filename'];
						$file_name = $this->db->escape($file_name);
						$image_url = $product['image']['meta']['href'];
						$image_path = DIR_IMAGE . $relative_path;
						$path_picture = $image_path . $file_name;
						if ($old_image_path && file_exists(DIR_IMAGE . $old_image_path)) {
							unlink(DIR_IMAGE . $old_image_path);
						}
						$this->uploadImage($image_url, $path_picture);
					}
				}

				if ($query->num_rows) {
					// update product
					$product_id = $query->row['product_id'];

					$this->db->query("UPDATE " . DB_PREFIX . "product SET wh_id='{$product['id']}', price=$price_rub, date_modified=now() WHERE product_id=$product_id");
					$this->db->query("UPDATE " . DB_PREFIX . "product_description SET name='$name', short_description='$description', meta_title='$name' WHERE product_id=$product_id");
					$this->log->write('Product updated');
				} else {
					// add product
					$this->db->query("INSERT INTO " . DB_PREFIX . "product SET weight_class_id=1, wh_id='{$product['id']}', stock_status_id=5, length_class_id=1, sku='$sku', price=$price_rub, status=1, date_added=now()");
					$product_id = $this->db->getLastId();
					$this->db->query("INSERT INTO " . DB_PREFIX . "product_description SET product_id={$product_id},  short_description='$description', language_id={$language_id}, name='$name', meta_title='$name'");
					$this->db->query("INSERT INTO " . DB_PREFIX . "product_to_store SET product_id={$product_id}, store_id={$store_id}");
					$this->log->write('Product added');
				}

				if (isset($product_to_categories)) {
					$this->db->query("DELETE FROM " . DB_PREFIX . "product_to_category WHERE product_id=" . $product_id);
					foreach ($product_to_categories as $key => $category_id) {
						$this->db->query("INSERT INTO " . DB_PREFIX . "product_to_category SET product_id=" . $product_id . ", category_id=" . $category_id . " " . ($key === 0 ? ", main_category= " . $category_id . " " : "") . " ");
					}
				}
				if (isset($file_name)) {
					$this->db->query("UPDATE " . DB_PREFIX . "product SET image='$relative_path_picture', product_id=$product_id WHERE product_id=$product_id");
				}

				$this->setQuantity(DB_PREFIX . 'product', 'product_id', $id, $product_id);
				// get and add options
				if (isset($product['variantsCount']) && $product['variantsCount'] != 0 || isset($product['modificationsCount']) && $product['modificationsCount'] != 0) {
					$this->addOption($product_id, $id);
				}

				$this->log->write('__________');
			} catch (Exception $e) {
				$this->log->write($e->getMessage());
			}
		}

		if (isset($this->request->server['HTTP_ORIGIN'])) {
			$this->response->addHeader('Access-Control-Allow-Origin: ' . $this->request->server['HTTP_ORIGIN']);
			$this->response->addHeader('Access-Control-Allow-Methods: GET, PUT, POST, DELETE, OPTIONS');
			$this->response->addHeader('Access-Control-Max-Age: 1000');
			$this->response->addHeader('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
		}
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	private function uploadImage($url, $path) {
		$file_content = $this->doCurlRequest($url, "GET");
		file_put_contents($path, $file_content);
	}

	private function productToCategory($categories) {
		$cats_array = array();
		$categories_separated = explode(';', $categories);
		foreach ($categories_separated as $category_row) {
			$cat_names = explode('\\', $category_row);
			$parent_cat_id = '';
			foreach ($cat_names as $category_name) {
				/* $this->log->write("SELECT * FROM " . DB_PREFIX . "category_description cd LEFT JOIN " . DB_PREFIX . "category_path cp ON (cd.category_id = cp.category_id) WHERE name = '" . trim($category_name) . "' " . (!empty($parent_cat_id) ? "AND path_id = " . $parent_cat_id . " " : "") . " "); */
				$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "category_description cd LEFT JOIN " . DB_PREFIX . "category_path cp ON (cd.category_id = cp.category_id) WHERE name = '" . trim($category_name) . "' " . (!empty($parent_cat_id) ? "AND path_id = " . $parent_cat_id . " " : "") . " ");

				$parent_cat_id = $query->row['path_id'];
				$final_cat_id = $query->row['category_id'];
			}
			$cats_array[] = $final_cat_id;
		}
		return $cats_array;
	}

	private function addOption($prod_id, $wh_id) {
		$json = $this->doCurlRequest('https://online.moysklad.ru/api/remap/1.1/entity/variant?filter=productid=' . $wh_id, "GET");
		$options = json_decode($json, true);
		$language_id = $this->config->get('config_language_id');
		$return_options = array();

		foreach ($options['rows'] as $key_option => $option) {
			foreach ($option['salePrices'] as $price) {
				if ($price['priceType'] == 'Цена интернет магазин') {
					$price_rub = $price['value'] / 100;
				}
			}

			foreach ($option['characteristics'] as $key_characteristic => $characteristic) {
				$characteristic_id = $this->db->escape($characteristic['id']);
				$characteristic_name = $this->db->escape($characteristic['name']);
				$characteristic_value = $this->db->escape($characteristic['value']);
				$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "option WHERE wh_id='$characteristic_id'");
				if ($query->num_rows) {
					$option_id = $query->row['option_id'];
					$this->db->query("UPDATE  " . DB_PREFIX . "option_description set name='$characteristic_name' WHERE option_id={$option_id}");
					$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "option_value_description WHERE name='$characteristic_value'");
					$option_value_id = $query->row['option_value_id'];

					if ($query->num_rows == 0) {
						$this->db->query("INSERT INTO " . DB_PREFIX . "option_value SET option_id={$option_id}, sort_order=0, wh_id='{$option['id']}'");
						$option_value_id = $this->db->getLastId();
						$this->db->query("INSERT INTO " . DB_PREFIX . "option_value_description SET option_value_id={$option_value_id}, language_id={$language_id}, option_id={$option_id}, name='$characteristic_value'");
					}

					// create variant
				} else {
					$option_id = $this->db->getLastId();
					$this->db->query("INSERT INTO " . DB_PREFIX . "option_description SET option_id={$option_id}, language_id={$language_id}, name='$characteristic_name'");
					$this->db->query("SELECT * FROM " . DB_PREFIX . "option WHERE 1");

					$this->db->query("INSERT INTO " . DB_PREFIX . "option_value SET option_id={$option_id}, sort_order=0, wh_id='{$option['id']}'");
					$option_value_id = $this->db->getLastId();
					$this->db->query("INSERT INTO " . DB_PREFIX . "option_value_description SET option_value_id={$option_value_id}, language_id={$language_id}, option_id={$option_id}, name='$characteristic_value'");
				}

				$return_options[$key_option]['id'] = $option['id'];
				$return_options[$key_option]['price'] = $price_rub;
				$return_options[$key_option]['values'][$key_characteristic] = array(
					'name' => $characteristic['name'],
					'option_id' => $option_id,
					'value' => $characteristic['value'],
					'option_value_id' => $option_value_id,
					'id' => $characteristic['id'],
				);
			}
		}
		$this->optionToProduct($prod_id, $return_options);
	}

	private function optionToProduct($product_id, $options = array()) {
		$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "product WHERE product_id=" . $product_id);
		$product_price = $query->row['price'];
		$product_quanity = array();
		// erase previous option to product information
		$this->db->query("DELETE FROM " . DB_PREFIX . "product_option WHERE product_id=$product_id");
		$this->db->query("DELETE FROM " . DB_PREFIX . "product_option_value WHERE product_id=$product_id");

		foreach ($options as $key_option => $option) {
			$price = abs($product_price - $option['price']);
			$price_prefix = ($option['price'] >= $product_price) ? '+' : '-';

			for ($i = 0; $i <= count($option['values']) - 1; $i++) {
				$value = $options[$key_option]['values'][$i];
				$related_option_value_id = ($i === 0) ? 0 : $options[$key_option]['values'][0]['option_value_id'];
				$required = ($i === 0) ? 1 : 0;
				$upper_char_value_id = ($i === 0) ? 0 : $options[$key_option]['values'][$i - 1]['option_value_id'];

				$query_option = $this->db->query("SELECT * FROM " . DB_PREFIX . "product_option_value WHERE product_id=$product_id AND option_id={$value['option_id']} AND related_option_value_id='$related_option_value_id' AND upper_char_value_id='$upper_char_value_id'");
				$query_char = $this->db->query("SELECT * FROM " . DB_PREFIX . "product_option_value WHERE product_id=$product_id AND option_id={$value['option_id']} AND related_option_value_id='$related_option_value_id' AND option_value_id={$value['option_value_id']} AND upper_char_value_id='$upper_char_value_id'");

				if (!$query_option->num_rows) {
					$this->db->query("INSERT INTO " . DB_PREFIX . "product_option SET product_id=$product_id, option_id={$value['option_id']}, required=$required, sort_order=$i");
				}

				if (!$query_char->num_rows) {
					$product_option_id = ($query_option->num_rows) ? $query_option->row['product_option_id'] : $this->db->getLastId();
					$set_price = ($i === count($option['values']) - 1) ? ", price=$price, price_prefix='$price_prefix'" : "";

					$this->db->query("INSERT INTO " . DB_PREFIX . "product_option_value SET product_option_id=$product_option_id, wh_id='{$options[$key_option]['id']}', product_id=$product_id, option_id={$value['option_id']}, option_value_id={$value['option_value_id']}, related_option_value_id='$related_option_value_id', upper_char_value_id='$upper_char_value_id' $set_price");
					if ($i === count($option['values']) - 1) {
						$option_last_id = $this->db->getLastId();
						$this->setQuantity(DB_PREFIX . 'product_option_value', 'product_option_value_id', $option['id'], $option_last_id);
					}
				}
			}
		}

		// add product quantity of all options
		$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "product_option_value WHERE product_id=$product_id");
		$quantity_product = array(
			'quantity_vlk' => 0,
			'quantity_vlk_2' => 0,
			'quantity_art' => 0,
			'quantity_uss' => 0,
			'quantity_ars' => 0,
		);
		foreach ($query->rows as $row) {
			$quantity_product['quantity_vlk'] += $row['quantity_vlk'];
			$quantity_product['quantity_vlk_2'] += $row['quantity_vlk_2'];
			$quantity_product['quantity_art'] += $row['quantity_art'];
			$quantity_product['quantity_uss'] += $row['quantity_uss'];
			$quantity_product['quantity_ars'] += $row['quantity_ars'];
		}
		$this->db->query("UPDATE " . DB_PREFIX . "product SET quantity_vlk={$quantity_product['quantity_vlk']}, quantity_vlk_2={$quantity_product['quantity_vlk_2']}, quantity_art={$quantity_product['quantity_art']}, quantity_uss={$quantity_product['quantity_uss']}, quantity_ars={$quantity_product['quantity_ars']} WHERE product_id=$product_id");
	}

	public function checkCounterparty ($data) {
		// delete '+' then delete first 7 or 8 number
		$phone = substr(preg_replace('/\D*/', '', $data['phone']), 1);

		$json = $this->doCurlRequest("https://online.moysklad.ru/api/remap/1.2/entity/counterparty?filter=email={$data['email']};phone~{$phone}","GET");
		$respond = json_decode($json, true);
		if ($respond['meta']['size']) {
			$data['counterparty'] = $respond;
			$this->checkOrder($data);
		} else {
			$this->addCounterparty($data);
		}
	}

	private function addCounterparty ($data) {
		$person['name'] = $data['name'];
		$person['phone'] = $data['phone'];
		$person['email'] = $data['email'];
		$person['attributes'][0]['id'] = '52b045f5-d086-11e5-7a69-8f550001a81a'; // id of required field 'Имя'
		$person['attributes'][0]['value'] = $data['name'];
		$person['attributes'][0]['meta']['href'] = 'https://online.moysklad.ru/api/remap/1.2/entity/counterparty/metadata/attributes/';
		$person['attributes'][0]['meta']['type'] = 'attributemetadata';
		$person['attributes'][0]['meta']['mediaType'] = 'application/json';
		$json = $this->doCurlRequest("https://online.moysklad.ru/api/remap/1.2/entity/counterparty", "POST", json_encode($person));
		$data['counterparty'] = json_decode($json, true);
		$this->checkOrder($data);
	}

	private function checkOrder ($data) {
		/* $this->log->write($data); */
		$order = array();
		$prefix = "ИМ-";
		$order['name'] = $prefix . $data['order_id'];
		$order['organization']['meta']['href'] = "https://online.moysklad.ru/api/remap/1.2/entity/organization/";
		$order['organization']['meta']['type'] = "organization";
		$order['organization']['meta']['mediaType'] = "application/json";
		$order['agent']['meta']['href'] = "https://online.moysklad.ru/api/remap/1.2/entity/counterparty/{$data['counterparty']['rows'][0]['id']}";
		$order['agent']['meta']['type'] = "counterparty";
		$order['agent']['meta']['mediaType'] = "application/json";

		switch ($data['city']) {
		case "uss":
			$location = '';
			break;
		case "vlk":
			$location = '';
			break;
		case "vlk_2":
			$location = '';
			break;
		case "ars":
			$location = '';
			break;
		case "art":
			$location = '';
			break;
		}

		$order['store']['meta']['href'] = "https://online.moysklad.ru/api/remap/1.2/entity/store/$location";
		$order['store']['meta']['type'] = "store";
		$order['store']['meta']['mediaType'] = "application/json";

		for ($i = 0; $i < count($data['products']); $i++) {
			$order['positions'][$i]['quantity'] = floatval($data['products'][$i]['quantity']);
			$order['positions'][$i]['reserve'] = floatval($data['products'][$i]['quantity']);
			$order['positions'][$i]['price'] = floatval($data['products'][$i]['total'] * 100);
			$order['positions'][$i]['discount'] = 0;
			$order['positions'][$i]['vat'] = 0;
			$order['positions'][$i]['vat'] = 0;
			$order['positions'][$i]['reserve'] = 0;
			$wh_id = $data['products'][$i]['wh_id'];
			$type = 'product';
			if (isset($data['products'][$i]['options'])) {
				$wh_id = $data['products'][$i]['options'][count($data['products'][$i]['options']) - 1]['wh_id']; // gets last option
				$type = 'variant';
			}

			$order['positions'][$i]['assortment']['meta']['href'] = "https://online.moysklad.ru/api/remap/1.2/entity/$type/$wh_id";
			$order['positions'][$i]['assortment']['meta']['type'] = $type;
			$order['positions'][$i]['assortment']['meta']['mediaType'] = 'application/json';
		}

		$json = $this->doCurlRequest("https://online.moysklad.ru/api/remap/1.2/entity/customerorder?filter=name=" . urlencode($prefix) . $data['order_id'],"GET");
		$respond = json_decode($json, true);

		if ($respond['meta']['size']) {
			$json = $this->doCurlRequest("https://online.moysklad.ru/api/remap/1.2/entity/customerorder/{$respond['rows'][0]['id']}", "PUT", json_encode($order));
			$this->log->write('PUT');
		} else {
			$json = $this->doCurlRequest("https://online.moysklad.ru/api/remap/1.2/entity/customerorder", "POST", json_encode($order));
			$this->log->write('POST');
		}
		$this->log->write('Moysklad answer:');
		$this->log->write($json);
	}

	private function setQuantity($bd_name, $search_by, $wh_id, $table_id) {
		$json = $this->doCurlRequest('https://online.moysklad.ru/api/remap/1.1/report/stock/bystore?product.id=' . $wh_id, "GET");
		$quantities = json_decode($json, true);
		$stock = array(
			'total' => 0,
			'uss' => 0,
			'art' => 0,
			'ars' => 0,
			'vlk' => 0,
			'vlk_2' => 0,
		);
		foreach ($quantities['rows'][0]['stockByStore'] as $quantity) {
			if ($quantity['name'] == '') {
				$stock['ars'] += $quantity['stock'];
			} else if ($quantity['name'] == '') {
				$stock['art'] += $quantity['stock'];
			} else if ($quantity['name'] == '') {
				$stock['vlk_2'] += $quantity['stock'];
			} else if ($quantity['name'] == '') {
				$stock['vlk'] += $quantity['stock'];
			} else if ($quantity['name'] == '' || $quantity['name'] == '') {
				$stock['uss'] += $quantity['stock'];
			}
			$stock['total'] += $quantity['stock'];
		}
		$this->db->query("UPDATE $bd_name SET quantity_uss = {$stock['uss']}, quantity_vlk = {$stock['vlk']}, quantity_vlk_2 = {$stock['vlk_2']}, quantity_ars = {$stock['ars']}, quantity_art = {$stock['art']}, quantity = {$stock['total']} WHERE $search_by = $table_id");

		/* $this->log->write('Stock updated: ' . $bd_name . ', ' . $search_by . ', ' . $table_id); */
		/* $this->log->write($stock); */
	}

	private function doCurlRequest($url, $method, $params = array()) {
		$ch = curl_init();

		$username = '';
		$password = '';

		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			'Content-Type: application/json',
			'Authorization: Basic ' . base64_encode("$username:$password"),
		));

		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

		if ($method == "POST") {
			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
		} elseif (isset($method)) {
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
		} else {
			curl_setopt($ch, CURLOPT_HTTPGET);
		}
		curl_setopt($ch, CURLOPT_URL, $url);

		//execute
		$result = curl_exec($ch);

		//close connection
		curl_close($ch);

		return $result;
	}

	/**
	 * возвращает перечень статусов ордеров
	 *
	 */
	public function statuses() {

		$json = array();
		$json['error'] = "";
		if (!isset($this->session->data['api_id'])) {
			$json['error'] = "Нет доступа";
		} else {

			try {

				$json['statuses'] = array();
				$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "order_status WHERE language_id = '" . (int) $this->config->get('config_language_id') . "' ORDER BY name");

				foreach ($query->rows as $row) {
					$json['statuses'][$row['order_status_id']] = $row['name'];
				}

			} catch (Exception $e) {
				$json['error'] = $e->getMessage();
			}
		}

		if (isset($this->request->server['HTTP_ORIGIN'])) {
			$this->response->addHeader('Access-Control-Allow-Origin: ' . $this->request->server['HTTP_ORIGIN']);
			$this->response->addHeader('Access-Control-Allow-Methods: GET, PUT, POST, DELETE, OPTIONS');
			$this->response->addHeader('Access-Control-Max-Age: 1000');
			$this->response->addHeader('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	/**
	 * Список ордеров по статусу
	 *
	 */
	public function orders() {

		$json = array();
		$json['error'] = "";
		if (!isset($this->session->data['api_id'])) {
			$json['error'] = "Нет доступа";
		} else {

			try {
				$status_id = $this->request->post['status_id'];
				$json['orders'] = array();
				$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "order` o    WHERE  o.store_id =  " . (int) $this->config->get('config_store_id') . " and o.order_status_id=" . $status_id);

				foreach ($query->rows as $row) {

					$products = array();

					$queryi = $this->db->query("SELECT op.name, p.sku,op.price,op.quantity,op.order_product_id FROM `" . DB_PREFIX . "order_product` op  join  `" . DB_PREFIX . "product` p on op.product_id = p.product_id   WHERE  op.order_id=" . $row['order_id']);
					foreach ($queryi->rows as $rowi) {

						$options = array();

						$queryo = $this->db->query("SELECT name,value  FROM `" . DB_PREFIX . "order_option`  WHERE   order_id=" . $row['order_id'] . " and order_product_id=" . $rowi['order_product_id']);
						foreach ($queryo->rows as $rowo) {

							$options[$rowo['name']] = $rowo['value'];
						}

						$rowi['_options_'] = $options;

						$products[] = $rowi;
					}

					if (count($products) == 0) {
						continue;
					}

					$row['_products_'] = $products;
					$json['orders'][] = $row;

				}

			} catch (Exception $e) {
				$json['error'] = $e->getMessage();
			}
		}

		if (isset($this->request->server['HTTP_ORIGIN'])) {
			$this->response->addHeader('Access-Control-Allow-Origin: ' . $this->request->server['HTTP_ORIGIN']);
			$this->response->addHeader('Access-Control-Allow-Methods: GET, PUT, POST, DELETE, OPTIONS');
			$this->response->addHeader('Access-Control-Max-Age: 1000');
			$this->response->addHeader('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	/**
	 * обновление статуса ордеров
	 *
	 */
	public function updateorder() {

		$json = array();
		$json['error'] = '';
		if (!isset($this->session->data['api_id'])) {
			$json['error'] = "Нет доступа";
		} else {

			try {
				$data = $this->request->post['data'];
				$data = str_replace('&quot;', '"', $data);

				$list = json_decode($data, true);

				foreach ($list as $order_id => $status) {
					$this->db->query("UPDATE `" . DB_PREFIX . "order` o  set o.order_status_id= {$status}   WHERE  o.store_id =  " . (int) $this->config->get('config_store_id') . " and o.order_id=" . $order_id);
				}

			} catch (Exception $e) {
				$json['error'] = $e->getMessage();
			}
		}

		if (isset($this->request->server['HTTP_ORIGIN'])) {
			$this->response->addHeader('Access-Control-Allow-Origin: ' . $this->request->server['HTTP_ORIGIN']);
			$this->response->addHeader('Access-Control-Allow-Methods: GET, PUT, POST, DELETE, OPTIONS');
			$this->response->addHeader('Access-Control-Max-Age: 1000');
			$this->response->addHeader('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	/**
	 * возвращает список  категоритй  товаров
	 *
	 */
	public function cats() {

		$json = array();

		if (!isset($this->session->data['api_id'])) {
			$json['error'] = "Нет доступа";
		} else {

			try {

				$json['cats'] = array();
				$sql = "SELECT cp.category_id AS category_id, GROUP_CONCAT(cd1.name ORDER BY cp.level SEPARATOR '&nbsp;&nbsp;&gt;&nbsp;&nbsp;') AS name   FROM " . DB_PREFIX . "category_path cp LEFT JOIN " . DB_PREFIX . "category c1 ON (cp.category_id = c1.category_id) LEFT JOIN " . DB_PREFIX . "category c2 ON (cp.path_id = c2.category_id) LEFT JOIN " . DB_PREFIX . "category_description cd1 ON (cp.path_id = cd1.category_id) LEFT JOIN " . DB_PREFIX . "category_description cd2 ON (cp.category_id = cd2.category_id) WHERE cd1.language_id = '" . (int) $this->config->get('config_language_id') . "' AND cd2.language_id = '" . (int) $this->config->get('config_language_id') . "'";
				$sql .= " GROUP BY cp.category_id order  by  name";
				$query = $this->db->query($sql);

				foreach ($query->rows as $row) {
					$json['cats'][$row['category_id']] = $row['name'];
				}

			} catch (Exception $e) {
				$json['error'] = $e->getMessage();
			}
		}

		if (isset($this->request->server['HTTP_ORIGIN'])) {
			$this->response->addHeader('Access-Control-Allow-Origin: ' . $this->request->server['HTTP_ORIGIN']);
			$this->response->addHeader('Access-Control-Allow-Methods: GET, PUT, POST, DELETE, OPTIONS');
			$this->response->addHeader('Access-Control-Max-Age: 1000');
			$this->response->addHeader('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	/**
	 * обновление  цен
	 *
	 */
	public function updateprice() {

		$json = array();
		$json['error'] = '';
		if (!isset($this->session->data['api_id'])) {
			$json['error'] = "Нет доступа";
		} else {

			try {
				$data = $this->request->post['data'];
				$data = str_replace('&quot;', '"', $data);

				$list = json_decode($data, true);

				foreach ($list as $sku => $price) {

					$this->db->query("UPDATE `" . DB_PREFIX . "product`    set price= {$price}   WHERE  sku =  '" . $this->db->escape($sku) . "'");
				}

			} catch (Exception $e) {
				$json['error'] = $e->getMessage();
			}
		}

		if (isset($this->request->server['HTTP_ORIGIN'])) {
			$this->response->addHeader('Access-Control-Allow-Origin: ' . $this->request->server['HTTP_ORIGIN']);
			$this->response->addHeader('Access-Control-Allow-Methods: GET, PUT, POST, DELETE, OPTIONS');
			$this->response->addHeader('Access-Control-Max-Age: 1000');
			$this->response->addHeader('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	/**
	 * Список  товаров
	 *
	 */
	public function getproducts() {

		$json = array();
		$json['error'] = "";
		if (!isset($this->session->data['api_id'])) {
			$json['error'] = "Нет доступа";
		} else {

			try {
				$language_id = (int) $this->config->get('config_language_id');
				$store_id = (int) $this->config->get('config_store_id');

				$json['products'] = array();
				$sql = "SELECT p.sku,p.price,p.image,pd.name,pd.description FROM `" . DB_PREFIX . "product` p  join  `" . DB_PREFIX . "product_description` pd on p.product_id=pd.product_id   WHERE  pd.language_id={$language_id}  and p.product_id in(select product_id from " . DB_PREFIX . "product_to_store  where store_id={$store_id} ) ";

				$query = $this->db->query($sql);

				foreach ($query->rows as $row) {
					if (strlen($row['sku']) == 0) {
						continue;
					}

					$json['products'][] = $row;

				}

			} catch (Exception $e) {
				$json['error'] = $e->getMessage();
			}
		}

		if (isset($this->request->server['HTTP_ORIGIN'])) {
			$this->response->addHeader('Access-Control-Allow-Origin: ' . $this->request->server['HTTP_ORIGIN']);
			$this->response->addHeader('Access-Control-Allow-Methods: GET, PUT, POST, DELETE, OPTIONS');
			$this->response->addHeader('Access-Control-Max-Age: 1000');
			$this->response->addHeader('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}
}
