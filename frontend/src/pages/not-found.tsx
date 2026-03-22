import { Layout } from "@/components/Layout";
import { AlertCircle } from "lucide-react";

export default function NotFound() {
  return (
    <Layout>
      <div className="flex-1 flex items-center justify-center">
        <div className="text-center">
          <AlertCircle className="w-12 h-12 text-muted-foreground mx-auto mb-4" />
          <h1 className="text-2xl font-bold text-foreground mb-2">
            404 - Page Not Found
          </h1>
          <p className="text-muted-foreground">
            The page you're looking for doesn't exist.
          </p>
        </div>
      </div>
    </Layout>
  );
}
