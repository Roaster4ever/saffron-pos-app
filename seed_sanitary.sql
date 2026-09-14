-- ============================================================
-- SAFFRON POS — Seed Data: Sanitary / Plumbing Store
-- Realistic brands, categories, units, products for Pakistan
-- ============================================================

USE pos_db;

-- ============================================================
-- BRANDS
-- ============================================================
INSERT INTO brands (name, description) VALUES
('Porta', 'Premium sanitaryware — commodes, basins, baths'),
('Master', 'PPR pipes, fittings, valves, plumbing solutions'),
('IIL', 'PVC pipes, fittings, solvent cement'),
('Sonex', 'Mixers, taps, shower sets, bathroom accessories'),
('Faisal', 'Kitchen and bathroom sinks, accessories'),
('National', 'Pipes, tanks, plumbing hardware'),
('Sonak', 'Faucets, angle valves, plumbing fittings'),
('Links', 'Shower enclosures, bathroom accessories'),
('Cera', 'Sanitaryware, faucets, tiles accessories'),
('Parryware', 'Premium sanitaryware and bathroom fittings'),
('Kartar', 'Kitchen sinks, stainless steel products'),
('Super', 'Water heaters, geysers, storage tanks'),
('Pride', 'PPR pipes and fittings'),
('Dawlance', 'Water dispensers, storage solutions'),
('Grauss', 'Ceramic sanitaryware, basins, commodes');

-- ============================================================
-- CATEGORIES
-- ============================================================
DELETE FROM categories WHERE id > 0;
ALTER TABLE categories AUTO_INCREMENT = 1;

INSERT INTO categories (name) VALUES
('Commodes'),
('Wash Basins'),
('Faucets & Mixers'),
('Showers'),
('PPR Pipes & Fittings'),
('PVC Pipes & Fittings'),
('Kitchen Sinks'),
('Angle Valves'),
('Water Tanks'),
('Flush Tanks & Cisterns'),
('Shower Enclosures'),
('Bathroom Accessories'),
('Floor Drains'),
('Traps & Waste'),
('Teflon Tape & Sealants'),
('Water Heaters'),
('Pipe Clamps & Supports'),
('Balls & Check Valves'),
('Pipe Cutters & Tools'),
('Tiles Accessories');

-- ============================================================
-- UNITS
-- ============================================================
INSERT INTO units (name, short_name, allows_decimal) VALUES
('Piece', 'pc', 0),
('Set', 'set', 0),
('Pair', 'pair', 0),
('Box', 'box', 0),
('Dozen', 'dz', 0),
('Meter', 'm', 1),
('Foot', 'ft', 1),
('Roll', 'roll', 0),
('Kilogram', 'kg', 1),
('Bag', 'bag', 0),
('Sheet', 'sheet', 0),
('Carton', 'ctn', 0),
('Gallon', 'gal', 1),
('Liter', 'L', 1),
('Pack', 'pack', 0);

-- ============================================================
-- PRICE LISTS
-- ============================================================
INSERT INTO price_lists (name, description, sort_order) VALUES
('Retail', 'Standard retail price', 1),
('Wholesale', 'Wholesale price for bulk buyers', 2),
('Contractor', 'Special price for contractors and builders', 3),
('Dealer', 'Dealer/reseller price', 4);

-- ============================================================
-- SPECIFICATION DEFINITIONS (per category)
-- ============================================================

-- Commodes
INSERT INTO spec_definitions (category_id, name, sort_order) VALUES
(1, 'Trap Type', 1),
(1, 'Flush Type', 2),
(1, 'Seat Type', 3),
(1, 'Material', 4),
(1, 'Color', 5),
(1, 'Shape', 6);

-- Wash Basins
INSERT INTO spec_definitions (category_id, name, sort_order) VALUES
(2, 'Mounting', 1),
(2, 'Material', 2),
(2, 'Color', 3),
(2, 'Size', 4);

-- Faucets & Mixers
INSERT INTO spec_definitions (category_id, name, sort_order) VALUES
(3, 'Finish', 1),
(3, 'Material', 2),
(3, 'Type', 3),
(3, 'Hole Configuration', 4);

-- Showers
INSERT INTO spec_definitions (category_id, name, sort_order) VALUES
(4, 'Finish', 1),
(4, 'Type', 2),
(4, 'Material', 3);

-- PPR Pipes & Fittings
INSERT INTO spec_definitions (category_id, name, sort_order) VALUES
(5, 'Diameter', 1),
(5, 'Material Grade', 2),
(5, 'Pressure Rating', 3),
(5, 'Color', 4);

-- PVC Pipes & Fittings
INSERT INTO spec_definitions (category_id, name, sort_order) VALUES
(6, 'Diameter', 1),
(6, 'Class', 2),
(6, 'Type', 3);

-- Kitchen Sinks
INSERT INTO spec_definitions (category_id, name, sort_order) VALUES
(7, 'Material', 1),
(7, 'Finish', 2),
(7, 'Bowl Count', 3),
(7, 'Size', 4);

-- Angle Valves
INSERT INTO spec_definitions (category_id, name, sort_order) VALUES
(8, 'Size', 1),
(8, 'Material', 2),
(8, 'Finish', 3);

-- ============================================================
-- PRODUCTS — Realistic sanitary/plumbing inventory
-- ============================================================
-- Clear old sample products (they were grocery items)
DELETE FROM sale_items WHERE sale_id IN (SELECT id FROM sales WHERE invoice_no LIKE 'INV-%');
DELETE FROM sales;
DELETE FROM sale_items;
DELETE FROM products;

ALTER TABLE products AUTO_INCREMENT = 1;

-- ---- COMMODES ----
INSERT INTO products (category_id, brand_id, name, sku, barcode, model, price, cost, min_price, wholesale_price, contractor_price, stock, low_stock_alert, taxable, gst_rate) VALUES
(1, 1, 'Porta One Piece Commode', 'POR-COM-001', '6901010100001', 'Porta One', 45000.00, 32000.00, 38000.00, 40000.00, 38500.00, 8, 2, 1, 18.00),
(1, 15, 'Grauss Dual Flush Commode', 'GRA-COM-001', '6901010100002', 'Grauss DF', 38000.00, 27000.00, 32000.00, 34000.00, 32500.00, 12, 3, 1, 18.00),
(1, 10, 'Parryware EWC Commode', 'PAR-COM-001', '6901010100003', 'Parryware EWC', 42000.00, 30000.00, 35000.00, 37000.00, 36000.00, 6, 2, 1, 18.00),
(1, 9, 'Cera Wall Hung Commode', 'CER-COM-001', '6901010100004', 'Cera WH', 52000.00, 38000.00, 44000.00, 46000.00, 44500.00, 4, 1, 1, 18.00);

-- ---- WASH BASINS ----
INSERT INTO products (category_id, brand_id, name, sku, barcode, model, price, cost, min_price, wholesale_price, contractor_price, stock, low_stock_alert, taxable, gst_rate) VALUES
(2, 1, 'Porta Pedestal Basin', 'POR-BAS-001', '6901010200001', 'Porta Ped', 18000.00, 12500.00, 15000.00, 16000.00, 15500.00, 15, 3, 1, 18.00),
(2, 15, 'Grauss Wall Hung Basin', 'GRA-BAS-001', '6901010200002', 'Grauss WH', 14000.00, 9500.00, 11500.00, 12500.00, 12000.00, 20, 5, 1, 18.00),
(2, 10, 'Parryware Counter Basin', 'PAR-BAS-001', '6901010200003', 'Parry Counter', 22000.00, 15500.00, 18000.00, 19500.00, 18500.00, 10, 2, 1, 18.00),
(2, 9, 'Cera Semi Pedestal Basin', 'CER-BAS-001', '6901010200004', 'Cera Semi', 16000.00, 11000.00, 13000.00, 14000.00, 13500.00, 8, 2, 1, 18.00);

-- ---- FAUCETS & MIXERS ----
INSERT INTO products (category_id, brand_id, name, sku, barcode, model, price, cost, min_price, wholesale_price, contractor_price, stock, low_stock_alert, taxable, gst_rate) VALUES
(3, 4, 'Sonex Basin Mixer Chrome', 'SON-MIX-001', '6901010300001', 'Sonex BM-Chrome', 8500.00, 5500.00, 7000.00, 7500.00, 7200.00, 25, 5, 1, 18.00),
(3, 4, 'Sonex Basin Mixer Matte Black', 'SON-MIX-002', '6901010300002', 'Sonex BM-Black', 9500.00, 6200.00, 7800.00, 8300.00, 8000.00, 18, 4, 1, 18.00),
(3, 4, 'Sonex Kitchen Mixer Chrome', 'SON-MIX-003', '6901010300003', 'Sonex KM-Chrome', 12000.00, 8000.00, 9800.00, 10500.00, 10000.00, 15, 3, 1, 18.00),
(3, 7, 'Sonak Angle Valve Chrome', 'SNK-AVL-001', '6901010300004', 'Sonak AV-Chrome', 1800.00, 1100.00, 1450.00, 1550.00, 1500.00, 50, 10, 1, 18.00),
(3, 7, 'Sonak Pillar Tap', 'SNK-TAP-001', '6901010300005', 'Sonak PT', 2200.00, 1400.00, 1800.00, 1900.00, 1850.00, 40, 8, 1, 18.00);

-- ---- SHOWERS ----
INSERT INTO products (category_id, brand_id, name, sku, barcode, model, price, cost, min_price, wholesale_price, contractor_price, stock, low_stock_alert, taxable, gst_rate) VALUES
(4, 4, 'Sonex Shower Set Chrome', 'SON-SHW-001', '6901010400001', 'Sonex SS-Chrome', 15000.00, 9800.00, 12000.00, 13000.00, 12500.00, 10, 3, 1, 18.00),
(4, 4, 'Sonex Rain Shower 8"', 'SON-SHW-002', '6901010400002', 'Sonex RS-8', 11000.00, 7200.00, 9000.00, 9500.00, 9200.00, 12, 3, 1, 18.00),
(4, 4, 'Sonex Hand Shower Set', 'SON-SHW-003', '6901010400003', 'Sonex HS', 6500.00, 4200.00, 5300.00, 5700.00, 5500.00, 20, 5, 1, 18.00);

-- ---- PPR PIPES & FITTINGS ----
INSERT INTO products (category_id, brand_id, name, sku, barcode, model, price, cost, min_price, wholesale_price, contractor_price, stock, low_stock_alert, taxable, gst_rate) VALUES
(5, 2, 'Master PPR Pipe 20mm (4m)', 'MST-PPR-020', '6901010500001', 'Master PPR 20', 320.00, 220.00, 260.00, 280.00, 270.00, 200, 50, 1, 18.00),
(5, 2, 'Master PPR Pipe 25mm (4m)', 'MST-PPR-025', '6901010500002', 'Master PPR 25', 420.00, 290.00, 340.00, 370.00, 355.00, 200, 50, 1, 18.00),
(5, 2, 'Master PPR Pipe 32mm (4m)', 'MST-PPR-032', '6901010500003', 'Master PPR 32', 620.00, 420.00, 500.00, 540.00, 525.00, 150, 40, 1, 18.00),
(5, 2, 'Master PPR Elbow 25mm', 'MST-PPR-E25', '6901010500004', 'Master PPR El 25', 85.00, 55.00, 68.00, 75.00, 72.00, 500, 100, 1, 18.00),
(5, 2, 'Master PPR Elbow 32mm', 'MST-PPR-E32', '6901010500005', 'Master PPR El 32', 140.00, 90.00, 112.00, 122.00, 118.00, 300, 80, 1, 18.00),
(5, 2, 'Master PPR Tee 25mm', 'MST-PPR-T25', '6901010500006', 'Master PPR Tee 25', 120.00, 78.00, 96.00, 105.00, 102.00, 400, 100, 1, 18.00),
(5, 2, 'Master PPR Coupling 25mm', 'MST-PPR-C25', '6901010500007', 'Master PPR Cp 25', 55.00, 35.00, 44.00, 48.00, 46.00, 600, 100, 1, 18.00),
(5, 2, 'Master PPR Reducer 25x20mm', 'MST-PPR-R25', '6901010500008', 'Master PPR Red 25x20', 75.00, 48.00, 60.00, 65.00, 63.00, 300, 60, 1, 18.00),
(5, 13, 'Pride PPR Pipe 20mm (4m)', 'PRI-PPR-020', '6901010500009', 'Pride PPR 20', 280.00, 190.00, 225.00, 245.00, 235.00, 180, 40, 1, 18.00),
(5, 2, 'Master PPR Socket Weld 25mm', 'MST-PPR-SW25', '6901010500010', 'Master PPR SW 25', 95.00, 62.00, 76.00, 83.00, 80.00, 350, 80, 1, 18.00);

-- ---- PVC PIPES & FITTINGS ----
INSERT INTO products (category_id, brand_id, name, sku, barcode, model, price, cost, min_price, wholesale_price, contractor_price, stock, low_stock_alert, taxable, gst_rate) VALUES
(6, 3, 'IIL PVC Pipe 4" (10ft)', 'IIL-PVC-4', '6901010600001', 'IIL PVC 4"', 650.00, 440.00, 520.00, 570.00, 550.00, 80, 20, 1, 18.00),
(6, 3, 'IIL PVC Pipe 3" (10ft)', 'IIL-PVC-3', '6901010600002', 'IIL PVC 3"', 450.00, 300.00, 360.00, 395.00, 380.00, 80, 20, 1, 18.00),
(6, 3, 'IIL PVC Elbow 4"', 'IIL-PVC-E4', '6901010600003', 'IIL PVC El 4"', 120.00, 78.00, 96.00, 105.00, 100.00, 200, 50, 1, 18.00),
(6, 3, 'IIL PVC Tee 4"', 'IIL-PVC-T4', '6901010600004', 'IIL PVC Tee 4"', 180.00, 118.00, 144.00, 158.00, 152.00, 150, 40, 1, 18.00),
(6, 3, 'IIL PVC Socket 4"', 'IIL-PVC-S4', '6901010600005', 'IIL PVC Socket 4"', 80.00, 52.00, 64.00, 70.00, 68.00, 250, 50, 1, 18.00),
(6, 6, 'National PVC Tank Pipe 2"', 'NAT-PVC-T2', '6901010600006', 'Nat PVC 2"', 280.00, 185.00, 224.00, 245.00, 238.00, 120, 30, 1, 18.00);

-- ---- KITCHEN SINKS ----
INSERT INTO products (category_id, brand_id, name, sku, barcode, model, price, cost, min_price, wholesale_price, contractor_price, stock, low_stock_alert, taxable, gst_rate) VALUES
(7, 5, 'Faisal SS Sink Single Bowl', 'FSA-SNK-001', '6901010700001', 'Faisal SS-SB', 16000.00, 11000.00, 13000.00, 14000.00, 13500.00, 8, 2, 1, 18.00),
(7, 5, 'Faisal SS Sink Double Bowl', 'FSA-SNK-002', '6901010700002', 'Faisal SS-DB', 24000.00, 16500.00, 19500.00, 21000.00, 20000.00, 5, 1, 1, 18.00),
(7, 11, 'Kartar SS Sink Single Bowl', 'KRT-SNK-001', '6901010700003', 'Kartar SS-SB', 14000.00, 9500.00, 11500.00, 12500.00, 12000.00, 10, 3, 1, 18.00);

-- ---- ANGLE VALVES ----
INSERT INTO products (category_id, brand_id, name, sku, barcode, model, price, cost, min_price, wholesale_price, contractor_price, stock, low_stock_alert, taxable, gst_rate) VALUES
(8, 7, 'Sonak Angle Valve 1/2"', 'SNK-AV2-001', '6901010800001', 'Sonak AV-12', 950.00, 600.00, 760.00, 820.00, 795.00, 60, 15, 1, 18.00),
(8, 7, 'Sonak Angle Valve 3/4"', 'SNK-AV2-002', '6901010800002', 'Sonak AV-34', 1400.00, 900.00, 1120.00, 1220.00, 1180.00, 40, 10, 1, 18.00),
(8, 4, 'Sonex Angle Valve Chrome', 'SON-AVL-001', '6901010800003', 'Sonex AV-Chrome', 1600.00, 1050.00, 1280.00, 1400.00, 1350.00, 30, 8, 1, 18.00);

-- ---- WATER TANKS ----
INSERT INTO products (category_id, brand_id, name, sku, barcode, model, price, cost, min_price, wholesale_price, contractor_price, stock, low_stock_alert, taxable, gst_rate) VALUES
(9, 6, 'National Water Tank 500L', 'NAT-TNK-500', '6901010900001', 'Nat Tank 500L', 22000.00, 15500.00, 18000.00, 19500.00, 18800.00, 6, 2, 1, 18.00),
(9, 6, 'National Water Tank 1000L', 'NAT-TNK-1000', '6901010900002', 'Nat Tank 1000L', 38000.00, 27000.00, 31000.00, 33500.00, 32500.00, 4, 1, 1, 18.00),
(9, 12, 'Super Water Tank 500L', 'SUP-TNK-500', '6901010900003', 'Super Tank 500L', 20000.00, 14000.00, 16500.00, 17800.00, 17200.00, 8, 2, 1, 18.00);

-- ---- FLUSH TANKS ----
INSERT INTO products (category_id, brand_id, name, sku, barcode, model, price, cost, min_price, wholesale_price, contractor_price, stock, low_stock_alert, taxable, gst_rate) VALUES
(10, 1, 'Porta Dual Flush Cistern', 'POR-FLT-001', '6901011000001', 'Porta DF Cistern', 8500.00, 5800.00, 6800.00, 7300.00, 7100.00, 10, 3, 1, 18.00),
(10, 15, 'Grauss Flush Tank', 'GRA-FLT-001', '6901011000002', 'Grauss FT', 6000.00, 4000.00, 4800.00, 5200.00, 5050.00, 12, 3, 1, 18.00);

-- ---- BATHROOM ACCESSORIES ----
INSERT INTO products (category_id, brand_id, name, sku, barcode, model, price, cost, min_price, wholesale_price, contractor_price, stock, low_stock_alert, taxable, gst_rate) VALUES
(12, 8, 'Links Towel Rod Chrome', 'LNK-TWR-001', '6901011200001', 'Links TR-Chrome', 3200.00, 2100.00, 2600.00, 2800.00, 2700.00, 20, 5, 1, 18.00),
(12, 8, 'Links Soap Dish Chrome', 'LNK-SD-001', '6901011200002', 'Links SD-Chrome', 1800.00, 1150.00, 1440.00, 1560.00, 1520.00, 25, 5, 1, 18.00),
(12, 8, 'Links Robe Hook Chrome', 'LNK-RHK-001', '6901011200003', 'Links RH-Chrome', 1200.00, 780.00, 960.00, 1040.00, 1010.00, 30, 8, 1, 18.00);

-- ---- FLOOR DRAINS ----
INSERT INTO products (category_id, brand_id, name, sku, barcode, model, price, cost, min_price, wholesale_price, contractor_price, stock, low_stock_alert, taxable, gst_rate) VALUES
(13, 7, 'Sonak Floor Drain 4"', 'SNK-FLD-001', '6901011300001', 'Sonak FD-4', 850.00, 550.00, 680.00, 740.00, 720.00, 80, 20, 1, 18.00),
(13, 7, 'Sonak Floor Drain 6"', 'SNK-FLD-002', '6901011300002', 'Sonak FD-6', 1400.00, 900.00, 1120.00, 1220.00, 1180.00, 50, 15, 1, 18.00);

-- ---- TRAPS & WASTE ----
INSERT INTO products (category_id, brand_id, name, sku, barcode, model, price, cost, min_price, wholesale_price, contractor_price, stock, low_stock_alert, taxable, gst_rate) VALUES
(14, 7, 'Sonak Bottle Trap Chrome', 'SNK-BTR-001', '6901011400001', 'Sonak BT-Chrome', 2200.00, 1450.00, 1760.00, 1900.00, 1850.00, 30, 8, 1, 18.00),
(14, 4, 'Sonex Floor Trap', 'SON-FLT-001', '6901011400002', 'Sonex FT', 1500.00, 980.00, 1200.00, 1300.00, 1260.00, 40, 10, 1, 18.00);

-- ---- TEFLON & SEALANTS ----
INSERT INTO products (category_id, brand_id, name, sku, barcode, model, price, cost, min_price, wholesale_price, contractor_price, stock, low_stock_alert, taxable, gst_rate) VALUES
(15, NULL, 'Teflon Tape 1/2" (10m)', NULL, '6901011500001', 'Teflon 10m', 120.00, 65.00, 96.00, 100.00, 98.00, 500, 100, 1, 18.00),
(15, NULL, 'Teflon Tape 3/4" (10m)', NULL, '6901011500002', 'Teflon 34-10m', 180.00, 95.00, 144.00, 155.00, 150.00, 400, 80, 1, 18.00),
(15, NULL, 'Pipe Sealant 100ml', NULL, '6901011500003', 'Pipe Seal 100ml', 350.00, 200.00, 280.00, 300.00, 295.00, 100, 20, 1, 18.00);

-- ---- WATER HEATERS ----
INSERT INTO products (category_id, brand_id, name, sku, barcode, model, price, cost, min_price, wholesale_price, contractor_price, stock, low_stock_alert, taxable, gst_rate) VALUES
(16, 12, 'Super Geyser 10L', 'SUP-GEY-010', '6901011600001', 'Super Geyser 10L', 35000.00, 25000.00, 29000.00, 31000.00, 30000.00, 5, 1, 1, 18.00),
(16, 12, 'Super Geyser 15L', 'SUP-GEY-015', '6901011600002', 'Super Geyser 15L', 42000.00, 30000.00, 35000.00, 37500.00, 36500.00, 4, 1, 1, 18.00);

-- ---- PIPE CLAMPS ----
INSERT INTO products (category_id, brand_id, name, sku, barcode, model, price, cost, min_price, wholesale_price, contractor_price, stock, low_stock_alert, taxable, gst_rate) VALUES
(17, NULL, 'Pipe Clamp 25mm', NULL, '6901011700001', 'Clamp 25mm', 65.00, 38.00, 52.00, 56.00, 54.00, 500, 100, 1, 18.00),
(17, NULL, 'Pipe Clamp 32mm', NULL, '6901011700002', 'Clamp 32mm', 85.00, 50.00, 68.00, 74.00, 72.00, 400, 80, 1, 18.00),
(17, NULL, 'Pipe Clamp 4"', NULL, '6901011700003', 'Clamp 4"', 150.00, 90.00, 120.00, 130.00, 126.00, 200, 40, 1, 18.00);

-- ---- BALL & CHECK VALVES ----
INSERT INTO products (category_id, brand_id, name, sku, barcode, model, price, cost, min_price, wholesale_price, contractor_price, stock, low_stock_alert, taxable, gst_rate) VALUES
(18, 7, 'Sonak Ball Valve 1/2"', 'SNK-BV2-001', '6901011800001', 'Sonak BV-12', 1200.00, 780.00, 960.00, 1040.00, 1010.00, 40, 10, 1, 18.00),
(18, 7, 'Sonak Ball Valve 1"', 'SNK-BV2-002', '6901011800002', 'Sonak BV-1', 3200.00, 2100.00, 2560.00, 2780.00, 2700.00, 20, 5, 1, 18.00),
(18, 2, 'Master Check Valve 1"', 'MST-CKV-001', '6901011800003', 'Master CKV-1', 2800.00, 1850.00, 2240.00, 2430.00, 2380.00, 15, 3, 1, 18.00);

-- ---- PIPE TOOLS ----
INSERT INTO products (category_id, brand_id, name, sku, barcode, model, price, cost, min_price, wholesale_price, contractor_price, stock, low_stock_alert, taxable, gst_rate) VALUES
(19, NULL, 'Pipe Cutter 1/2"-2"', NULL, '6901011900001', 'Pipe Cutter', 2500.00, 1650.00, 2000.00, 2180.00, 2120.00, 8, 2, 1, 18.00),
(19, NULL, 'Pipe Wrench 14"', NULL, '6901011900002', 'Pipe Wrench 14', 3500.00, 2300.00, 2800.00, 3050.00, 2980.00, 6, 2, 1, 18.00);

-- ============================================================
-- SPECIFICATION VALUES for key products
-- ============================================================

-- Porta One Piece Commode (id=1)
INSERT INTO product_specs (product_id, spec_def_id, value) VALUES
(1, (SELECT id FROM spec_definitions WHERE name='Trap Type' AND category_id=1), 'P-Trap'),
(1, (SELECT id FROM spec_definitions WHERE name='Flush Type' AND category_id=1), 'Dual Flush'),
(1, (SELECT id FROM spec_definitions WHERE name='Seat Type' AND category_id=1), 'Soft Close'),
(1, (SELECT id FROM spec_definitions WHERE name='Material' AND category_id=1), 'Ceramic'),
(1, (SELECT id FROM spec_definitions WHERE name='Color' AND category_id=1), 'White'),
(1, (SELECT id FROM spec_definitions WHERE name='Shape' AND category_id=1), 'One Piece');

-- Grauss Dual Flush Commode (id=2)
INSERT INTO product_specs (product_id, spec_def_id, value) VALUES
(2, (SELECT id FROM spec_definitions WHERE name='Trap Type' AND category_id=1), 'S-Trap'),
(2, (SELECT id FROM spec_definitions WHERE name='Flush Type' AND category_id=1), 'Dual Flush'),
(2, (SELECT id FROM spec_definitions WHERE name='Material' AND category_id=1), 'Ceramic'),
(2, (SELECT id FROM spec_definitions WHERE name='Color' AND category_id=1), 'White');

-- Master PPR Pipe 25mm (id=17)
INSERT INTO product_specs (product_id, spec_def_id, value) VALUES
(17, (SELECT id FROM spec_definitions WHERE name='Diameter' AND category_id=5), '25mm'),
(17, (SELECT id FROM spec_definitions WHERE name='Material Grade' AND category_id=5), 'PN-20'),
(17, (SELECT id FROM spec_definitions WHERE name='Pressure Rating' AND category_id=5), '20 Bar'),
(17, (SELECT id FROM spec_definitions WHERE name='Color' AND category_id=5), 'Green');

-- Sonex Basin Mixer Chrome (id=9)
INSERT INTO product_specs (product_id, spec_def_id, value) VALUES
(9, (SELECT id FROM spec_definitions WHERE name='Finish' AND category_id=3), 'Chrome'),
(9, (SELECT id FROM spec_definitions WHERE name='Material' AND category_id=3), 'Brass'),
(9, (SELECT id FROM spec_definitions WHERE name='Type' AND category_id=3), 'Single Lever'),
(9, (SELECT id FROM spec_definitions WHERE name='Hole Configuration' AND category_id=3), 'Single Hole');

-- Sonex Basin Mixer Matte Black (id=10)
INSERT INTO product_specs (product_id, spec_def_id, value) VALUES
(10, (SELECT id FROM spec_definitions WHERE name='Finish' AND category_id=3), 'Matte Black'),
(10, (SELECT id FROM spec_definitions WHERE name='Material' AND category_id=3), 'Brass'),
(10, (SELECT id FROM spec_definitions WHERE name='Type' AND category_id=3), 'Single Lever'),
(10, (SELECT id FROM spec_definitions WHERE name='Hole Configuration' AND category_id=3), 'Single Hole');

-- Sonex Shower Set Chrome (id=14)
INSERT INTO product_specs (product_id, spec_def_id, value) VALUES
(14, (SELECT id FROM spec_definitions WHERE name='Finish' AND category_id=4), 'Chrome'),
(14, (SELECT id FROM spec_definitions WHERE name='Type' AND category_id=4), 'Overhead + Hand'),
(14, (SELECT id FROM spec_definitions WHERE name='Material' AND category_id=4), 'Stainless Steel');

-- Faisal SS Sink Single Bowl (id=28)
INSERT INTO product_specs (product_id, spec_def_id, value) VALUES
(28, (SELECT id FROM spec_definitions WHERE name='Material' AND category_id=7), 'Stainless Steel'),
(28, (SELECT id FROM spec_definitions WHERE name='Finish' AND category_id=7), 'Brushed'),
(28, (SELECT id FROM spec_definitions WHERE name='Bowl Count' AND category_id=7), 'Single'),
(28, (SELECT id FROM spec_definitions WHERE name='Size' AND category_id=7), '24x18 inch');

-- ============================================================
-- EXPENSE CATEGORIES
-- ============================================================
INSERT INTO expense_categories (name) VALUES
('Rent'),
('Electricity'),
('Salaries'),
('Transport'),
('Repairs'),
('Supplies'),
('Marketing'),
('Utilities'),
('Miscellaneous');

-- ============================================================
-- DEFAULT SETTINGS
-- ============================================================
INSERT INTO settings (setting_key, setting_value, setting_type, description) VALUES
('shop_name', 'Saffron Sanitary', 'string', 'Shop name displayed on invoices and reports'),
('shop_address', 'Main Market, Lahore', 'string', 'Shop address'),
('shop_phone', '0321-1234567', 'string', 'Shop phone number'),
('shop_whatsapp', '0321-1234567', 'string', 'WhatsApp number'),
('shop_email', 'info@saffronpos.com', 'string', 'Email address'),
('currency', 'Rs: ', 'string', 'Currency prefix'),
('default_tax_rate', '18', 'decimal', 'Default GST rate percentage'),
('invoice_footer', 'Thank you for your business!', 'string', 'Footer text on invoices'),
('quotation_footer', 'This quotation is valid for 15 days.', 'string', 'Footer text on quotations'),
('low_stock_default', '5', 'integer', 'Default low stock alert threshold'),
('quotation_validity_days', '15', 'integer', 'Default validity period for quotations');
