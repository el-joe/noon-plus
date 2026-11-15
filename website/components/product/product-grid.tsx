import type { ReactNode } from "react";
import { ProductCard, type ProductCardData } from "./product-card";

/** Product grid: 2 columns under 768px, 3 at tablet, 4–6 at desktop (page-builder responsive rules). */
export function ProductGrid({ products, columns = 5, children }: { products: ProductCardData[]; columns?: 4 | 5 | 6; children?: ReactNode }) {
  const cols = { 4: "lg:grid-cols-4", 5: "lg:grid-cols-5", 6: "lg:grid-cols-6" }[columns];
  return (
    <div className={`grid grid-cols-2 gap-3 md:grid-cols-3 ${cols}`}>
      {products.map((product, index) => (
        <ProductCard key={`${product.slug}-${index}`} product={product} />
      ))}
      {children}
    </div>
  );
}
